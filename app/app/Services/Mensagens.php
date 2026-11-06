<?php

namespace App\Services;

use App\Jobs\EnviarMensagem;
use App\Models\MessageDelivery;
use App\Models\Ordem;
use App\Models\User;
use App\Support\Dinheiro;
use Illuminate\Auth\Access\AuthorizationException;

/** Mensagens ao cliente pelo canal oficial (outbox + fila). Falha não é entrega. */
class Mensagens
{
    public const MARCADOR_LINK = '[link do atendimento]';

    /** Chamar dentro da transação do fato de negócio; o envio só ocorre após o commit. */
    public function enfileirar(Ordem $ordem, string $tipo, ?string $tokenLink = null, ?int $valorCentavos = null): MessageDelivery
    {
        $ordem->loadMissing('cliente', 'unidade', 'veiculo');
        $nome = strtok($ordem->cliente->nome, ' ');
        $texto = match ($tipo) {
            'entrada' => "Olá, {$nome}! A {$ordem->unidade->nome} registrou a entrada {$this->objeto($ordem)} (atendimento {$ordem->numero}). Acompanhe: ".self::MARCADOR_LINK,
            'orcamento' => "{$nome}, o orçamento do atendimento {$ordem->numero} está disponível para consulta: ".self::MARCADOR_LINK,
            'cobranca' => "{$nome}, a cobrança Pix de ".Dinheiro::formatar((int) $valorCentavos)." do atendimento {$ordem->numero} está disponível. Pague somente pelo link oficial: ".self::MARCADOR_LINK,
            'conclusao' => "{$nome}, ".($ordem->veiculo ? "o veículo {$ordem->veiculo->placaFormatada()} está pronto" : 'o item “'.\Illuminate\Support\Str::limit((string) $ordem->item_descricao, 60).'” está pronto')." para retirada na {$ordem->unidade->nome}. Detalhes: ".self::MARCADOR_LINK,
        };
        $m = MessageDelivery::create(['ordem_id' => $ordem->id, 'unidade_id' => $ordem->unidade_id, 'tipo' => $tipo, 'canal' => app(\App\Integracoes\Mensagens\Mensageiro::class)->canal(),
            'destino' => $ordem->cliente->telefone, 'conteudo' => $texto, 'segredo_link' => $tokenLink]);
        EnviarMensagem::dispatch($m->id)->afterCommit();

        return $m;
    }

    /** "do veículo ABC-1234" ou, em OS sem veículo, "do item “Jogo de rodas…”". */
    private function objeto(Ordem $ordem): string
    {
        return $ordem->veiculo ? "do veículo {$ordem->veiculo->placaFormatada()}" : 'do item “'.\Illuminate\Support\Str::limit((string) $ordem->item_descricao, 60).'”';
    }

    /**
     * Aviso de serviço ao colaborador (AvisosColaborador): texto já sem preço nem contato do cliente; nunca leva link do portal.
     * Chamar dentro da transação do fato de negócio; o envio só ocorre após o commit.
     */
    public function enfileirarParaColaborador(Ordem $ordem, User $colaborador, string $texto): MessageDelivery
    {
        $m = MessageDelivery::create(['ordem_id' => $ordem->id, 'unidade_id' => $ordem->unidade_id, 'tipo' => 'aviso_colaborador',
            'canal' => app(\App\Integracoes\Mensagens\Mensageiro::class)->canal(), 'destino' => $colaborador->telefone, 'conteudo' => $texto, 'segredo_link' => null]);
        EnviarMensagem::dispatch($m->id)->afterCommit();

        return $m;
    }

    /**
     * OS enviada ao cliente por WhatsApp (canal oficial, outbox). Texto só com dados do cliente: nome, loja, número, total aprovado,
     * saldo e link do portal. Chamar dentro da transação; o envio só ocorre após o commit.
     */
    public function enfileirarDocumentoOs(Ordem $ordem, string $tokenLink): MessageDelivery
    {
        $m = MessageDelivery::create(['ordem_id' => $ordem->id, 'unidade_id' => $ordem->unidade_id, 'tipo' => 'documento_os',
            'canal' => app(\App\Integracoes\Mensagens\Mensageiro::class)->canal(), 'destino' => $ordem->cliente->telefone,
            'conteudo' => $this->textoDocumentoOs($ordem, self::MARCADOR_LINK), 'segredo_link' => $tokenLink]);
        EnviarMensagem::dispatch($m->id)->afterCommit();

        return $m;
    }

    /** Texto da OS por WhatsApp (mesmo no envio oficial e no "Abrir no meu WhatsApp"). Sem observações internas nem histórico. */
    public function textoDocumentoOs(Ordem $ordem, string $link): string
    {
        $ordem->loadMissing('cliente', 'unidade');
        $nome = strtok((string) $ordem->cliente->nome, ' ');
        $loja = \App\Models\Empresa::atual()->nome_fantasia ?: $ordem->unidade->nome;
        $r = app(Financeiro::class)->resumo($ordem);
        $valores = $r['aprovado'] > 0
            ? 'Total aprovado: '.Dinheiro::formatar((int) $r['aprovado']).'. Saldo a pagar: '.Dinheiro::formatar(max(0, (int) $r['saldo'])).'.'
            : 'O orçamento ainda não foi aprovado.';

        return "Olá, {$nome}! A {$loja} enviou a ordem de serviço {$ordem->numero}. {$valores} Veja e imprima: {$link} Não é documento fiscal.";
    }

    public function reenviar(MessageDelivery $m, User $ator): void
    {
        $ordem = Ordem::findOrFail($m->ordem_id);
        if (! $ator->pode('registrar_entrada') || ! $ator->acessaUnidade($ordem->unidade_id)) {
            throw new AuthorizationException('Sem permissão.');
        }
        // Token não é retido após falha definitiva: o reenvio gera um link novo.
        // Aviso ao colaborador nunca leva link do portal do cliente (que mostra valores).
        $token = $m->tipo === 'aviso_colaborador' ? null : ($m->segredo_link ?? app(Orcamentos::class)->novoLink($ordem, $ator));
        $m->update(['status' => 'pendente', 'segredo_link' => $token, 'ultimo_erro' => null]);
        Auditoria::registrar('mensagem_reenviada', $ordem, depois: ['mensagem_id' => $m->id, 'tipo' => $m->tipo], ator: $ator);
        EnviarMensagem::dispatch($m->id);
    }

    /**
     * Varredura do outbox (mesma regra do comando oficina:reprocessar-pendencias): redespacha mensagens pendentes
     * paradas há mais de 5 minutos. O job trava a linha e ignora o que não estiver pendente, então não duplica envio.
     *
     * @param  list<int>|null  $unidades  null = todas
     */
    public function redespacharPendentes(?array $unidades = null): int
    {
        $ids = MessageDelivery::where('status', 'pendente')->where('updated_at', '<', now()->subMinutes(5))
            ->when($unidades !== null, fn ($q) => $q->whereIn('unidade_id', $unidades))->pluck('id');
        $ids->each(fn ($id) => EnviarMensagem::dispatch($id));

        return $ids->count();
    }
}
