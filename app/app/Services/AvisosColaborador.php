<?php

namespace App\Services;

use App\Enums\StatusOrdem;
use App\Models\MessageDelivery;
use App\Models\Ordem;
use App\Models\User;
use App\Support\Dinheiro;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Aviso de serviço ao colaborador pelo WhatsApp (opcional). Substitui o grupo de WhatsApp dos colaboradores
 * (decisões de 30/10/2026): leva OS, veículo/placa (ou item), serviço resumido e entrega prevista —
 * NUNCA preço, valores nem contato do cliente. Sai pelo outbox de mensagens (em modo simulado nada é enviado).
 * Só é gerado se o colaborador tiver telefone no perfil e ligar "Receber avisos de serviço pelo WhatsApp" em /conta.
 */
class AvisosColaborador
{
    /** Chamado no evento "updated" da OS (dentro da transação do fato de negócio). */
    public function aoAtualizarOrdem(Ordem $ordem): void
    {
        $motivo = null;
        if ($ordem->wasChanged('mecanico_id') && $ordem->mecanico_id !== null && Auth::id() !== $ordem->mecanico_id) {
            $motivo = 'atribuicao'; // quem se atribui sozinho (mecânico assumindo da fila) não precisa de aviso
        } elseif ($ordem->wasChanged('status') && $ordem->status === StatusOrdem::Autorizado && $ordem->mecanico_id !== null) {
            $motivo = 'autorizado';
        }
        if ($motivo === null) {
            return;
        }
        try {
            // Savepoint: falha no aviso nunca desfaz a atribuição/aprovação.
            DB::transaction(fn () => $this->avisar($ordem, $motivo));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function avisar(Ordem $ordem, string $motivo): ?MessageDelivery
    {
        $colaborador = $ordem->mecanico_id ? User::find($ordem->mecanico_id) : null;
        if (! $colaborador || ! $colaborador->ativo || ! $colaborador->telefone || ! $colaborador->receber_avisos_whatsapp) {
            return null;
        }
        $m = app(Mensagens::class)->enfileirarParaColaborador($ordem, $colaborador, self::texto($ordem, $motivo));
        // Auditoria sem conteúdo nem telefone: só o fato e o destinatário.
        Auditoria::registrar('aviso_colaborador_enfileirado', $ordem, depois: [
            'mensagem_id' => $m->id, 'colaborador_id' => $colaborador->id, 'motivo' => $motivo, 'canal' => $m->canal,
        ]);

        return $m;
    }

    public static function texto(Ordem $ordem, string $motivo): string
    {
        $ordem->loadMissing('veiculo', 'unidade', 'cliente');
        $v = $ordem->veiculo;
        $veiculo = $v
            ? trim(implode(' ', array_filter([$v->modelo, $v->cor]))).($v->placa ? ' · placa '.$v->placaFormatada() : '')
            : (string) ($ordem->getAttribute('item_descricao') ?: 'item sem veículo');
        $prazo = $ordem->entrega_prevista_em ? $ordem->entrega_prevista_em->format('d/m/Y') : 'a definir';
        $titulo = $motivo === 'autorizado' ? 'Serviço autorizado' : 'Serviço atribuído a você';
        $texto = "{$titulo} — OS {$ordem->numero} ({$ordem->unidade?->nome}). Veículo/item: {$veiculo}. Serviço: "
            .self::resumoServico($ordem).". Entrega prevista: {$prazo}. Detalhes e fotos no sistema.";

        return self::sanitizar($texto, $ordem->cliente?->telefone);
    }

    /** Itens aprovados sem preço (qtd × descrição) ou, sem orçamento aprovado, o motivo da entrada. */
    public static function resumoServico(Ordem $ordem, int $limite = 160): string
    {
        $ordem->loadMissing('orcamentoAprovado.itens');
        $itens = $ordem->orcamentoAprovado?->itens ?? collect();
        $texto = $itens->isNotEmpty()
            ? $itens->map(fn ($i) => Dinheiro::quantidadeExibicao((string) $i->quantidade).' × '.$i->descricao)->implode('; ')
            : (string) $ordem->motivo;

        return mb_strimwidth(trim(preg_replace('/\s+/', ' ', $texto)), 0, $limite, '…');
    }

    /** Defesa em profundidade: retira valores em reais e números de telefone que alguém tenha digitado em descrições. */
    public static function sanitizar(string $texto, ?string $telefoneCliente = null): string
    {
        $texto = preg_replace('/R\s*\$\s*[\d.,]*/iu', '[valor omitido]', $texto);
        $texto = preg_replace('/\+?\(?\d{2}\)?[\s.-]?9?\d{4}[\s.-]?\d{4}\b/u', '[número omitido]', $texto);
        $texto = preg_replace('/\d{8,}/', '[número omitido]', $texto);
        if ($telefoneCliente && strlen($telefoneCliente) >= 8) {
            $texto = str_replace([$telefoneCliente, substr($telefoneCliente, -8), substr($telefoneCliente, -9)], '[número omitido]', $texto);
        }

        return $texto;
    }
}
