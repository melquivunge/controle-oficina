<?php

namespace App\Services;

use App\Integracoes\Mensagens\Mensageiro;
use App\Integracoes\Mensagens\MensageiroDrope;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Orcamento;
use App\Models\OrcamentoAvulso;
use App\Models\Ordem;
use App\Models\Unidade;
use App\Models\User;
use App\Models\WhatsappConversa;
use App\Services\WhatsApp\Atendimento;
use App\Support\Dinheiro;
use App\Support\Normaliza;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Orçamento sem entrada do veículo. Quem orça (permissão orcar) na unidade. Link do cliente só para ver; a decisão é
 * registrada pela equipe (mesma regra das OS desde 06/11/2026). Desconto só na OS: aqui o preço não fica abaixo da tabela.
 */
class OrcamentosAvulsos
{
    public const VALIDADE_DIAS = 15;

    public function autorizar(User $user, OrcamentoAvulso|int $alvo): void
    {
        $unidadeId = is_int($alvo) ? $alvo : $alvo->unidade_id;
        if (! $user->pode('orcar') || ! $user->acessaUnidade($unidadeId)) {
            throw new AuthorizationException('Sem permissão para orçamentos desta unidade.');
        }
    }

    /** @param array{unidade_id:int, telefone?:?string, nome?:?string, cliente_id?:?int, placa?:?string, veiculo_descricao?:?string, conversa_id?:?int} $d */
    public function criar(User $ator, array $d): OrcamentoAvulso
    {
        $this->autorizar($ator, (int) $d['unidade_id']);
        Unidade::where('ativa', true)->findOrFail($d['unidade_id']);
        $conversa = ! empty($d['conversa_id']) ? WhatsappConversa::findOrFail($d['conversa_id']) : null;
        if ($conversa && $conversa->unidade_id !== null && $conversa->unidade_id !== (int) $d['unidade_id']) {
            throw ValidationException::withMessages(['unidade_id' => 'A conversa é de outra unidade: o orçamento precisa ser da mesma unidade do WhatsApp.']);
        }

        return DB::transaction(function () use ($ator, $d, $conversa) {
            $cliente = $this->cliente($d, $conversa);
            $seq = app(Entradas::class)->proximoNumero((int) $d['unidade_id']);
            $o = OrcamentoAvulso::create([
                'unidade_id' => $d['unidade_id'], 'sequencia' => $seq, 'numero' => sprintf('%s-%05d', Unidade::find($d['unidade_id'])->codigo, $seq),
                'cliente_id' => $cliente->id, 'conversa_id' => $conversa?->id,
                'placa' => ($p = Normaliza::placa($d['placa'] ?? '')) !== '' ? $p : null,
                'veiculo_descricao' => trim((string) ($d['veiculo_descricao'] ?? '')) ?: null,
                'validade' => now()->addDays(self::VALIDADE_DIAS)->toDateString(), 'criado_por' => $ator->id,
            ]);
            Auditoria::registrar('orcamento_avulso_criado', $o, depois: $o->only('numero', 'cliente_id', 'placa', 'conversa_id'), unidadeId: $o->unidade_id, ator: $ator);

            return $o;
        });
    }

    /** Cliente da conversa (vinculado), por id, pelo telefone ou novo. */
    private function cliente(array $d, ?WhatsappConversa $conversa): Cliente
    {
        if ($conversa) {
            return $conversa->cliente ?? throw ValidationException::withMessages(['cliente' => 'Vincule ou cadastre o cliente na conversa antes de orçar.']);
        }
        if (! empty($d['cliente_id'])) {
            return Cliente::ativos()->findOrFail($d['cliente_id']);
        }
        $telefone = Normaliza::telefone($d['telefone'] ?? '');

        return Cliente::ativos()->where('telefone', $telefone)->orderBy('id')->first()
            ?? Cliente::create(['nome' => trim((string) $d['nome']), 'telefone' => $telefone]);
    }

    public function salvarItens(OrcamentoAvulso $o, User $ator, array $itens, ?string $observacao, ?string $validade): OrcamentoAvulso
    {
        $this->autorizar($ator, $o);
        $linhas = app(Orcamentos::class)->normalizarItens($itens, $o->unidade_id);
        foreach ($linhas as $n => $l) {
            if ($l['preco_tabela_centavos'] !== null && $l['preco_unitario_centavos'] < $l['preco_tabela_centavos']) {
                throw ValidationException::withMessages(["itens.$n.preco" => 'Preço abaixo da tabela. Desconto só na OS, depois da entrada do veículo.']);
            }
        }
        if ($validade !== null && ($validade < now()->toDateString() || $validade > now()->addDays(90)->toDateString())) {
            throw ValidationException::withMessages(['validade' => 'Validade entre hoje e 90 dias.']);
        }

        return DB::transaction(function () use ($o, $ator, $linhas, $observacao, $validade) {
            $o = OrcamentoAvulso::lockForUpdate()->findOrFail($o->id);
            if ($o->status !== 'rascunho') {
                throw ValidationException::withMessages(['itens' => 'Só o rascunho pode ser alterado. Cancele e faça outro se precisar mudar.']);
            }
            $antes = $o->only('total_centavos');
            $o->update(['itens' => $linhas,
                'total_centavos' => array_sum(array_column($linhas, 'total_centavos')), 'observacao' => $observacao ?: null, 'validade' => $validade ?? $o->validade]);
            Auditoria::registrar('orcamento_avulso_itens', $o, $antes, ['total_centavos' => $o->total_centavos, 'itens' => count($linhas)], unidadeId: $o->unidade_id, ator: $ator);

            return $o;
        });
    }

    /** Marca como enviado (rascunho com itens) e gera um link novo (o anterior deixa de valer). Retorna o token em texto puro. */
    public function gerarLink(OrcamentoAvulso $o, User $ator): string
    {
        $this->autorizar($ator, $o);

        return DB::transaction(function () use ($o, $ator) {
            $o = OrcamentoAvulso::lockForUpdate()->findOrFail($o->id);
            if (! in_array($o->status, ['rascunho', 'enviado'], true) || ! $o->itens) {
                throw ValidationException::withMessages(['itens' => $o->itens ? 'Este orçamento já foi decidido.' : 'Inclua ao menos um item antes de enviar.']);
            }
            $token = Str::random(48);
            $o->update(['status' => 'enviado', 'enviado_em' => $o->enviado_em ?? now(), 'token_hash' => hash('sha256', $token), 'link_expira_em' => now()->addDays(30)]);
            Auditoria::registrar('orcamento_avulso_enviado', $o, depois: ['total_centavos' => $o->total_centavos], unidadeId: $o->unidade_id, ator: $ator);

            return $token;
        });
    }

    public function texto(OrcamentoAvulso $o, string $link): string
    {
        $o->loadMissing('cliente', 'unidade');
        $nome = strtok((string) $o->cliente->nome, ' ');
        $loja = Empresa::atual()->nome_fantasia ?: $o->unidade->nome;

        return "Olá, {$nome}! A {$loja} preparou o orçamento {$o->numero}: total ".Dinheiro::formatar($o->total_centavos)
            .', válido até '.$o->validade->format('d/m/Y').". Veja os itens: {$link} Para aprovar, é só responder esta mensagem.";
    }

    /** Envio pelo WhatsApp da loja: pela conversa da Central quando houver (fica no histórico), senão pelo canal de mensagens. */
    public function enviarPelaLoja(OrcamentoAvulso $o, User $ator, string $link): void
    {
        $texto = $this->texto($o, $link);
        if ($o->conversa) {
            app(Atendimento::class)->responder($o->conversa, $ator, $texto);

            return;
        }
        $canal = MensageiroDrope::paraUnidade($o->unidade_id) ?? app(Mensageiro::class);
        $canal->enviar((string) $o->cliente->telefone, $texto);
    }

    public function decidir(OrcamentoAvulso $o, User $ator, string $decisao, string $canal, ?string $obs): void
    {
        $this->autorizar($ator, $o);
        DB::transaction(function () use ($o, $ator, $decisao, $canal, $obs) {
            $o = OrcamentoAvulso::lockForUpdate()->findOrFail($o->id);
            if ($o->status !== 'enviado') {
                throw ValidationException::withMessages(['decisao' => 'Só orçamento enviado e sem decisão pode ser aprovado ou recusado.']);
            }
            if ($decisao === 'aprovado' && $o->vencido()) {
                throw ValidationException::withMessages(['decisao' => 'Orçamento vencido em '.$o->validade->format('d/m/Y').'. Faça um novo com os preços atuais.']);
            }
            $o->update(['status' => $decisao, 'decidido_em' => now(), 'decisao_canal' => $canal, 'decisao_observacao' => $obs ?: null, 'decidido_por' => $ator->id]);
            Auditoria::registrar('orcamento_avulso_'.$decisao, $o, depois: ['canal' => $canal], motivo: $obs, unidadeId: $o->unidade_id, ator: $ator);
        });
    }

    public function cancelar(OrcamentoAvulso $o, User $ator, string $motivo): void
    {
        $this->autorizar($ator, $o);
        DB::transaction(function () use ($o, $ator, $motivo) {
            $o = OrcamentoAvulso::lockForUpdate()->findOrFail($o->id);
            if (in_array($o->status, ['convertido', 'cancelado'], true)) {
                throw ValidationException::withMessages(['motivo' => 'Este orçamento não pode mais ser cancelado.']);
            }
            $o->update(['status' => 'cancelado', 'token_hash' => null]);
            Auditoria::registrar('orcamento_avulso_cancelado', $o, motivo: $motivo, unidadeId: $o->unidade_id, ator: $ator);
        });
    }

    /** Chamado por Entradas::registrar dentro da transação: trava o avulso aprovado e devolve-o para usar o número. */
    public function travarParaEntrada(int $id, int $unidadeId): OrcamentoAvulso
    {
        $o = OrcamentoAvulso::lockForUpdate()->findOrFail($id);
        if ($o->status !== 'aprovado' || $o->ordem_id !== null || $o->unidade_id !== $unidadeId) {
            throw ValidationException::withMessages(['unidade_id' => 'O orçamento '.$o->numero.' precisa estar aprovado, sem OS e ser desta unidade.']);
        }

        return $o;
    }

    /** Copia os itens num rascunho de orçamento da OS (passa pelo diagnóstico e pela aprovação normal) e marca o avulso. */
    public function concluirEntrada(OrcamentoAvulso $o, Ordem $ordem, User $ator): void
    {
        $orc = Orcamento::create(['ordem_id' => $ordem->id, 'unidade_id' => $ordem->unidade_id, 'versao' => 1, 'status' => 'rascunho',
            'subtotal_centavos' => $o->total_centavos, 'desconto_centavos' => 0, 'total_centavos' => $o->total_centavos, 'criado_por' => $ator->id,
            'observacao_cliente' => "Aprovado pelo cliente no orçamento {$o->numero} (".$o->decidido_em?->format('d/m/Y').', '.(OrcamentoAvulso::CANAIS[$o->decisao_canal] ?? $o->decisao_canal).').']);
        foreach (array_values($o->itens) as $n => $l) {
            $orc->itens()->create(['posicao' => $n + 1, 'descricao' => $l['descricao'], 'quantidade' => $l['quantidade'], 'preco_unitario_centavos' => $l['preco_unitario_centavos'],
                'total_centavos' => $l['total_centavos'], 'tipo' => $l['tipo'] ?? 'servico', 'catalogo_item_id' => $l['catalogo_item_id'] ?? null,
                'preco_tabela_centavos' => $l['preco_tabela_centavos'] ?? null, 'desconto_max_percentual' => $l['desconto_max_percentual'] ?? null]);
        }
        $o->update(['status' => 'convertido', 'ordem_id' => $ordem->id, 'token_hash' => null]);
        Auditoria::registrar('orcamento_avulso_convertido', $o, depois: ['ordem' => $ordem->numero], unidadeId: $o->unidade_id, ator: $ator);
    }
}
