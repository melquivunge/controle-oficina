<?php

namespace App\Services;

use App\Enums\StatusOrdem;
use App\Integracoes\Pix\ProvedorPix;
use App\Models\Cobranca;
use App\Models\Devolucao;
use App\Models\Entrega;
use App\Models\FechamentoCaixa;
use App\Models\Orcamento;
use App\Models\Ordem;
use App\Models\Pagamento;
use App\Models\Recebedor;
use App\Models\User;
use App\Support\Fuso;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Regras financeiras. Saldo = total aprovado − (recebimentos efetivos − devoluções).
 * Recebimentos em revisão não quitam. Pix só é confirmado pelo provedor.
 */
class Financeiro
{
    public function __construct(private ProvedorPix $pix) {}

    /** @return array{aprovado:int, recebido:int, devolvido:int, em_revisao:int, saldo:int} */
    public function resumo(Ordem $ordem): array
    {
        $aprovado = (int) Orcamento::where('ordem_id', $ordem->id)->where('status', 'aprovado')->value('total_centavos');
        $recebido = (int) Pagamento::where('ordem_id', $ordem->id)->whereIn('status', ['confirmado', 'registrado'])->sum('valor_centavos');
        // Devolução de pagamento em revisão não abate: o próprio pagamento ainda não conta como recebido.
        // Só devoluções concluídas abatem (Pix processando ou recusado não saiu da conta).
        $devolvido = (int) Devolucao::where('ordem_id', $ordem->id)->where('status', 'concluida')
            ->whereHas('pagamento', fn ($q) => $q->whereIn('status', ['confirmado', 'registrado']))->sum('valor_centavos');
        $revisao = (int) Pagamento::where('ordem_id', $ordem->id)->where('status', 'em_revisao')->sum('valor_centavos');

        return ['aprovado' => $aprovado, 'recebido' => $recebido, 'devolvido' => $devolvido, 'em_revisao' => $revisao, 'saldo' => $aprovado - ($recebido - $devolvido)];
    }

    /** Cobrança Pix na conta oficial da unidade, ligada à versão aprovada. Atendente não escolhe recebedor. */
    public function gerarCobranca(Ordem $ordem, User $ator, ?int $valorCentavos = null): Cobranca
    {
        $this->exigir($ator, 'cobrar', $ordem);
        $cobranca = DB::transaction(function () use ($ordem, $ator, $valorCentavos) {
            $o = Ordem::lockForUpdate()->findOrFail($ordem->id);
            if (! in_array($o->status, StatusOrdem::recebiveis(), true)) {
                throw ValidationException::withMessages(['cobranca' => 'Só é possível cobrar ordens com orçamento aprovado e não canceladas.']);
            }
            $orc = Orcamento::where('ordem_id', $o->id)->where('status', 'aprovado')->firstOrFail();
            $saldo = $this->resumo($o)['saldo'];
            $valor = $valorCentavos ?? $saldo;
            if ($saldo <= 0 || $valor <= 0 || $valor > $saldo) {
                throw ValidationException::withMessages(['valor' => 'O valor deve ser maior que zero e no máximo igual ao saldo ('.\App\Support\Dinheiro::formatar(max(0, $saldo)).').']);
            }
            $recebedor = Recebedor::where('unidade_id', $o->unidade_id)->where('ativo', true)->first()
                ?? throw ValidationException::withMessages(['cobranca' => 'A unidade não tem conta oficial de recebimento configurada. Peça ao proprietário.']);

            $aberta = Cobranca::where('ordem_id', $o->id)->whereIn('status', ['criando', 'pendente'])->lockForUpdate()->first();
            if ($aberta && $aberta->valor_centavos === $valor && $aberta->orcamento_id === $orc->id && $aberta->recebedor_id === $recebedor->id) {
                return $aberta; // pedido repetido: mesma cobrança
            }
            if ($aberta) {
                $this->cancelarCobranca($aberta, 'substituída por nova cobrança', $ator);
            }
            $seq = Cobranca::where('ordem_id', $o->id)->count() + 1;
            $c = Cobranca::create([
                'ordem_id' => $o->id, 'unidade_id' => $o->unidade_id, 'orcamento_id' => $orc->id, 'recebedor_id' => $recebedor->id,
                'provedor' => $this->pix->nome(), 'valor_centavos' => $valor, 'status' => 'criando', 'criado_por' => $ator->id,
                'chave_idempotencia' => hash('sha256', implode('|', [$o->id, $orc->id, $valor, $seq])),
            ]);
            Auditoria::registrar('cobranca_criada', $o, depois: ['cobranca_id' => $c->id, 'valor_centavos' => $valor, 'versao' => $orc->versao, 'recebedor_id' => $recebedor->id], ator: $ator);

            return $c;
        });

        return $cobranca->status === 'criando' ? $this->registrarNoProvedor($cobranca) : $cobranca;
    }

    /** Chamada externa fora da transação; a chave idempotente evita cobrança duplicada em nova tentativa. */
    public function registrarNoProvedor(Cobranca $c): Cobranca
    {
        try {
            $r = $this->pix->criarCobranca($c, $c->recebedor->identificador_conta);
            DB::transaction(function () use ($c, $r) {
                $c->update(['txid' => $r['txid'], 'copia_e_cola' => $r['copia_e_cola'], 'expira_em' => $r['expira_em'], 'status' => 'pendente', 'erro' => null]);
                $ordem = $c->ordem()->first();
                app(Mensagens::class)->enfileirar($ordem, 'cobranca', app(Orcamentos::class)->novoLink($ordem, User::find($c->criado_por)), $c->valor_centavos);
            });
        } catch (\Throwable $e) {
            report($e);
            $c->update(['status' => 'falha', 'erro' => mb_substr($e->getMessage(), 0, 500)]);
            Auditoria::registrar('cobranca_falhou', $c->ordem, depois: ['cobranca_id' => $c->id], motivo: mb_substr($e->getMessage(), 0, 200), atorTipo: 'sistema');
        }

        return $c->fresh();
    }

    /**
     * Confirma pelo estado autoritativo do provedor (webhook, conciliação ou job).
     * Valida conta, moeda e valor; idempotente pelo e2e_id; não reverte pagamento confirmado.
     */
    public function conciliarCobranca(string $txid): string
    {
        $estado = $this->pix->consultar($txid);

        return DB::transaction(function () use ($txid, $estado) {
            $c = Cobranca::where('txid', $txid)->lockForUpdate()->first();
            if (! $c) {
                return 'cobranca_desconhecida';
            }
            if ($estado['status'] !== 'paga') {
                if ($estado['status'] === 'expirada' && $c->status === 'pendente') {
                    $c->update(['status' => 'expirada']);
                    Auditoria::registrar('cobranca_expirada', $c->ordem()->first(), depois: ['cobranca_id' => $c->id], atorTipo: 'sistema');

                    return 'expirada';
                }

                return 'sem_pagamento'; // evento atrasado/antigo não altera nada
            }
            if (Pagamento::where('id_provedor', $estado['e2e_id'])->exists()) {
                return 'duplicado';
            }
            $ordem = Ordem::lockForUpdate()->findOrFail($c->ordem_id);
            $valor = (int) $estado['valor_pago_centavos'];
            $problemas = [];
            if ($estado['conta'] !== $c->recebedor->identificador_conta) {
                $problemas[] = 'conta recebedora diferente da cadastrada';
            }
            if ($estado['moeda'] !== 'BRL') {
                $problemas[] = 'moeda diferente de BRL';
            }
            if ($valor !== $c->valor_centavos) {
                $problemas[] = 'valor pago diferente do cobrado';
            }
            if ($c->status !== 'pendente') {
                $problemas[] = "pagamento de cobrança {$c->status}";
            }
            if ($valor > $this->resumo($ordem)['saldo']) {
                $problemas[] = 'pagamento excede o saldo';
            }
            $p = Pagamento::create([
                'ordem_id' => $ordem->id, 'unidade_id' => $ordem->unidade_id, 'cobranca_id' => $c->id, 'meio' => 'pix',
                'valor_centavos' => $valor, 'status' => $problemas ? 'em_revisao' : 'confirmado', 'motivo_revisao' => $problemas ? implode('; ', $problemas) : null,
                'id_provedor' => $estado['e2e_id'], 'recebido_em' => $estado['pago_em'] ?? now(),
            ]);
            if ($c->status === 'pendente') {
                $c->update(['status' => 'paga']);
            }
            Auditoria::registrar($problemas ? 'pix_em_revisao' : 'pix_confirmado', $ordem, depois: ['pagamento_id' => $p->id, 'valor_centavos' => $valor, 'cobranca_id' => $c->id],
                motivo: $problemas ? implode('; ', $problemas) : null, atorTipo: 'sistema');

            return $problemas ? 'em_revisao' : 'confirmado';
        });
    }

    /** Dinheiro ou cartão em maquininha externa: registro manual, nunca confirmação bancária. */
    /**
     * Dinheiro, cartão na maquininha (recibo opcional) ou Pix recebido no app do banco (09/11/2026: comprovante obrigatório
     * e declaração de que o valor foi conferido no extrato — comprovante de Pix falso é golpe comum).
     */
    public function registrarManual(Ordem $ordem, User $ator, string $meio, int $valorCentavos, ?string $observacao = null,
        ?\Illuminate\Http\UploadedFile $comprovante = null, bool $conferidoExtrato = false): Pagamento
    {
        $this->exigir($ator, 'receber_manual', $ordem);
        if (! in_array($meio, ['dinheiro', 'cartao_externo', 'pix_manual'], true)) {
            throw ValidationException::withMessages(['meio' => 'Forma de pagamento inválida.']);
        }
        if ($meio === 'pix_manual' && (! $comprovante || ! $conferidoExtrato)) {
            throw ValidationException::withMessages(['comprovante' => 'Para Pix recebido no aplicativo do banco, anexe o comprovante e confirme que conferiu o valor no extrato.']);
        }
        if ($comprovante && (! in_array($comprovante->getMimeType(), ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true) || $comprovante->getSize() > 8 * 1024 * 1024)) {
            throw ValidationException::withMessages(['comprovante' => 'Comprovante deve ser foto (JPG, PNG, WEBP) ou PDF de até 8 MB.']);
        }

        return DB::transaction(function () use ($ordem, $ator, $meio, $valorCentavos, $observacao, $comprovante) {
            $o = Ordem::lockForUpdate()->findOrFail($ordem->id);
            if (! in_array($o->status, StatusOrdem::recebiveis(), true) || ! Orcamento::where('ordem_id', $o->id)->where('status', 'aprovado')->exists()) {
                throw ValidationException::withMessages(['valor' => 'Não há valor aprovado a receber nesta ordem.']);
            }
            $saldo = $this->resumo($o)['saldo'];
            if ($valorCentavos <= 0 || $valorCentavos > $saldo) {
                throw ValidationException::withMessages(['valor' => 'O valor deve ser maior que zero e no máximo igual ao saldo ('.\App\Support\Dinheiro::formatar(max(0, $saldo)).').']);
            }
            if (FechamentoCaixa::where('unidade_id', $o->unidade_id)->where('data', Fuso::exibir(now(), 'Y-m-d'))->exists()) {
                throw ValidationException::withMessages(['valor' => 'O caixa de hoje já foi fechado. Peça ao gerente para registrar o recebimento como exceção no dia seguinte.']);
            }
            $arquivo = $comprovante?->storeAs("comprovantes/{$o->unidade_id}/{$o->id}", \Illuminate\Support\Str::uuid().'.'.$comprovante->extension(), 'local');
            $p = Pagamento::create(['ordem_id' => $o->id, 'unidade_id' => $o->unidade_id, 'meio' => $meio, 'valor_centavos' => $valorCentavos,
                'status' => 'registrado', 'recebido_em' => now(), 'registrado_por' => $ator->id, 'motivo_revisao' => $observacao,
                'comprovante_caminho' => $arquivo ?: null, 'comprovante_mime' => $comprovante?->getMimeType()]);
            Auditoria::registrar('recebimento_manual', $o, depois: ['pagamento_id' => $p->id, 'meio' => $meio, 'valor_centavos' => $valorCentavos], motivo: $observacao, ator: $ator);

            return $p;
        });
    }

    /**
     * Devolução de um recebimento. Pix: solicitada ao provedor fora da transação, com chave idempotente
     * (processando → concluida | falhou). Dinheiro/cartão externo: registro manual, nasce concluída.
     * Limite: valor do pagamento − devoluções processando ou concluídas (falhas não reservam valor).
     */
    public function devolver(Pagamento $pagamento, User $ator, int $valorCentavos, string $motivo): Devolucao
    {
        $this->exigir($ator, 'registrar_devolucao', $pagamento->ordem);

        $d = DB::transaction(function () use ($pagamento, $ator, $valorCentavos, $motivo) {
            $p = Pagamento::lockForUpdate()->findOrFail($pagamento->id);
            $disponivel = $this->disponivelParaDevolver($p);
            if ($valorCentavos <= 0 || $valorCentavos > $disponivel) {
                throw ValidationException::withMessages(['valor' => 'A devolução deve ser maior que zero e no máximo '.\App\Support\Dinheiro::formatar($disponivel).'.']);
            }
            $pix = $p->meio === 'pix';
            if ($pix && blank($p->id_provedor)) {
                throw ValidationException::withMessages(['valor' => 'Este Pix não tem identificação do banco; a devolução não pode ser solicitada ao provedor.']);
            }
            // Devolução manual total de recebimento em revisão: o valor é efetivo, mas não deve quitar a ordem.
            // No Pix isso só acontece quando o provedor concluir (ver concluirDevolucao).
            if (! $pix && $p->status === 'em_revisao' && $valorCentavos === $disponivel) {
                $p->update(['status' => 'confirmado', 'revisado_em' => now(), 'revisado_por' => $ator->id]);
            }
            $seq = Devolucao::where('pagamento_id', $p->id)->count() + 1;
            $d = Devolucao::create(['pagamento_id' => $p->id, 'ordem_id' => $p->ordem_id, 'unidade_id' => $p->unidade_id, 'valor_centavos' => $valorCentavos,
                'motivo' => $motivo, 'autorizado_por' => $ator->id, 'status' => $pix ? 'processando' : 'concluida', 'concluida_em' => $pix ? null : now(),
                'chave_idempotencia' => 'dev-'.substr(hash('sha256', implode('|', [$p->id, $seq, $valorCentavos])), 0, 60)]);
            Auditoria::registrar($pix ? 'devolucao_solicitada' : 'devolucao_registrada', $p->ordem,
                depois: ['pagamento_id' => $p->id, 'devolucao_id' => $d->id, 'valor_centavos' => $valorCentavos, 'meio' => $p->meio], motivo: $motivo, ator: $ator);

            return $d;
        });

        return $d->status === 'processando' ? $this->solicitarDevolucaoAoProvedor($d) : $d;
    }

    /** Nova tentativa de devolução que falhou: mesma chave idempotente (o provedor não duplica). */
    public function tentarDevolucaoNovamente(Devolucao $devolucao, User $ator): Devolucao
    {
        $pagamento = Pagamento::findOrFail($devolucao->pagamento_id);
        $this->exigir($ator, 'registrar_devolucao', $pagamento->ordem);

        $d = DB::transaction(function () use ($devolucao, $ator) {
            $p = Pagamento::lockForUpdate()->findOrFail($devolucao->pagamento_id);
            $d = Devolucao::lockForUpdate()->findOrFail($devolucao->id);
            if ($d->status !== 'falhou') {
                throw ValidationException::withMessages(['devolucao' => 'Só é possível tentar de novo uma devolução que falhou.']);
            }
            if (\App\Models\ResolucaoPendencia::where('tipo', 'devolucao_falhou')->where('referencia_id', $d->id)->exists()) {
                throw ValidationException::withMessages(['devolucao' => 'Esta devolução já foi resolvida manualmente; uma nova tentativa poderia devolver em dobro.']);
            }
            $disponivel = $this->disponivelParaDevolver($p);
            if ($d->valor_centavos > $disponivel) {
                throw ValidationException::withMessages(['devolucao' => 'O valor desta devolução excede o disponível agora ('.\App\Support\Dinheiro::formatar($disponivel).').']);
            }
            $d->update(['status' => 'processando', 'mensagem' => null]);
            Auditoria::registrar('devolucao_retentada', $p->ordem, depois: ['pagamento_id' => $p->id, 'devolucao_id' => $d->id, 'valor_centavos' => $d->valor_centavos], ator: $ator);

            return $d;
        });

        return $this->solicitarDevolucaoAoProvedor($d);
    }

    /** Chamada externa fora da transação; o resultado só é aplicado se a devolução ainda estiver em processamento. */
    public function solicitarDevolucaoAoProvedor(Devolucao $d): Devolucao
    {
        $pagamento = Pagamento::findOrFail($d->pagamento_id);
        try {
            $r = $this->pix->devolver($pagamento, (int) $d->valor_centavos, $d->chave_idempotencia);
        } catch (\Throwable $e) {
            report($e);
            $r = ['status' => 'recusada', 'id_provedor' => null, 'mensagem' => 'Falha ao contatar o provedor: '.$e->getMessage()];
        }

        DB::transaction(function () use ($d, $r) {
            $p = Pagamento::lockForUpdate()->findOrFail($d->pagamento_id); // mesma ordem de locks de devolver()
            $atual = Devolucao::lockForUpdate()->findOrFail($d->id);
            if ($atual->status !== 'processando' || $r['status'] === 'processando') {
                if ($r['status'] === 'processando' && $r['id_provedor']) {
                    $atual->update(['id_provedor' => $r['id_provedor']]);
                }

                return; // aguardando confirmação assíncrona, ou já tratada
            }
            $ordem = Ordem::findOrFail($atual->ordem_id);
            if ($r['status'] === 'concluida') {
                $atual->update(['status' => 'concluida', 'id_provedor' => $r['id_provedor'], 'mensagem' => null, 'concluida_em' => now()]);
                $concluido = (int) Devolucao::where('pagamento_id', $p->id)->where('status', 'concluida')->sum('valor_centavos');
                if ($p->status === 'em_revisao' && $concluido >= $p->valor_centavos) {
                    $p->update(['status' => 'confirmado', 'revisado_em' => now(), 'revisado_por' => $atual->autorizado_por]);
                }
                Auditoria::registrar('devolucao_concluida', $ordem, depois: ['devolucao_id' => $atual->id, 'valor_centavos' => $atual->valor_centavos, 'id_provedor' => $r['id_provedor']], atorTipo: 'sistema');
            } else {
                $mensagem = mb_substr((string) ($r['mensagem'] ?: 'Provedor recusou a devolução.'), 0, 500);
                $atual->update(['status' => 'falhou', 'mensagem' => $mensagem]);
                Auditoria::registrar('devolucao_falhou', $ordem, depois: ['devolucao_id' => $atual->id, 'valor_centavos' => $atual->valor_centavos], motivo: mb_substr($mensagem, 0, 200), atorTipo: 'sistema');
            }
        });

        return $d->fresh();
    }

    private function disponivelParaDevolver(Pagamento $p): int
    {
        return $p->valor_centavos - (int) Devolucao::where('pagamento_id', $p->id)->whereIn('status', Devolucao::RESERVAM_LIMITE)->sum('valor_centavos');
    }

    /** Divergência aceita após conferência (ex.: valor pago a mais combinado com o cliente). */
    public function aceitarRevisao(Pagamento $pagamento, User $ator, string $motivo): void
    {
        $this->exigir($ator, 'resolver_divergencias', $pagamento->ordem);
        DB::transaction(function () use ($pagamento, $ator, $motivo) {
            $p = Pagamento::lockForUpdate()->findOrFail($pagamento->id);
            if ($p->status !== 'em_revisao') {
                throw ValidationException::withMessages(['pagamento' => 'Este recebimento não está em revisão.']);
            }
            $p->update(['status' => 'confirmado', 'revisado_em' => now(), 'revisado_por' => $ator->id]);
            Auditoria::registrar('divergencia_aceita', $p->ordem, depois: ['pagamento_id' => $p->id], motivo: $motivo, ator: $ator);
        });
    }

    /**
     * Entrega atômica: saldo e status verificados sob lock. Saldo coberto por combinado vigente entrega sem exceção
     * (registra o combinado). Sem quitação nem combinado exige permissão, motivo e gera exceção.
     */
    public function entregar(Ordem $ordem, User $ator, ?string $motivoExcecao = null): Entrega
    {
        $this->exigir($ator, 'entregar', $ordem);

        return DB::transaction(function () use ($ordem, $ator, $motivoExcecao) {
            $o = Ordem::lockForUpdate()->findOrFail($ordem->id);
            if ($o->status !== StatusOrdem::ProntoParaEntrega) {
                throw ValidationException::withMessages(['entrega' => 'A ordem precisa estar pronta para entrega.']);
            }
            $resumo = $this->resumo($o);
            $saldo = $resumo['saldo'];
            // Saldo coberto por combinado vigente (parcelas com vencimento): entrega normal, sem exceção.
            $combinado = $saldo > 0 ? app(Combinados::class)->situacao($o, $resumo) : null;
            $combinadoId = ($combinado['cobre'] ?? false) ? $combinado['combinado']->id : null;
            $excecao = $saldo !== 0 && ! $combinadoId;
            if ($excecao) {
                if (! $ator->pode('liberar_sem_quitacao')) {
                    throw new AuthorizationException('Saldo em aberto. A liberação sem quitação exige gerente.');
                }
                if (blank($motivoExcecao)) {
                    throw ValidationException::withMessages(['motivo' => 'Informe o motivo da liberação sem quitação.']);
                }
            }
            $e = Entrega::create(['ordem_id' => $o->id, 'unidade_id' => $o->unidade_id, 'entregue_por' => $ator->id, 'saldo_centavos' => $saldo,
                'excecao' => $excecao, 'excecao_motivo' => $excecao ? $motivoExcecao : null, 'autorizado_por' => $excecao ? $ator->id : null, 'entregue_em' => now(),
                'combinado_id' => $combinadoId]);
            app(Ordens::class)->transicionar($o, StatusOrdem::Entregue, $ator, $excecao ? $motivoExcecao : null);
            Auditoria::registrar($excecao ? 'entrega_excecao' : ($combinadoId ? 'entrega_com_combinado' : 'entrega'), $o,
                depois: ['saldo_centavos' => $saldo] + ($combinadoId ? ['combinado_id' => $combinadoId] : []), motivo: $excecao ? $motivoExcecao : null, ator: $ator);
            $ordem->setRawAttributes($o->fresh()->getAttributes(), true);

            return $e;
        });
    }

    /** Fechamento do dia local: revisa recebimentos manuais e registra contagem física do dinheiro. */
    public function fecharCaixa(int $unidadeId, string $data, User $ator, int $dinheiroContado, ?string $observacao): FechamentoCaixa
    {
        if (! $ator->pode('fechar_caixa') || ! $ator->acessaUnidade($unidadeId)) {
            throw new AuthorizationException('Sem permissão para fechar o caixa desta unidade.');
        }

        return DB::transaction(function () use ($unidadeId, $data, $ator, $dinheiroContado, $observacao) {
            if (FechamentoCaixa::where('unidade_id', $unidadeId)->where('data', $data)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['data' => 'O caixa deste dia já foi fechado.']);
            }
            $doDia = $this->recebimentosDoDia($unidadeId, $data);
            $f = FechamentoCaixa::create([
                'unidade_id' => $unidadeId, 'data' => $data, 'fechado_por' => $ator->id, 'observacao' => $observacao,
                'dinheiro_sistema_centavos' => $this->dinheiroEsperado($unidadeId, $data, $doDia),
                'dinheiro_contado_centavos' => $dinheiroContado,
                'cartao_sistema_centavos' => (int) $doDia->where('meio', 'cartao_externo')->where('status', 'registrado')->sum('valor_centavos'),
                'pix_confirmado_centavos' => (int) $doDia->where('meio', 'pix')->where('status', 'confirmado')->sum('valor_centavos'),
            ]);
            Pagamento::whereIn('id', $doDia->where('status', 'registrado')->pluck('id'))->whereNull('fechamento_id')
                ->update(['fechamento_id' => $f->id, 'revisado_em' => now(), 'revisado_por' => $ator->id]);
            $diferenca = $dinheiroContado - $f->dinheiro_sistema_centavos;
            Auditoria::registrar('caixa_fechado', $f, depois: $f->only('data', 'dinheiro_sistema_centavos', 'dinheiro_contado_centavos', 'cartao_sistema_centavos', 'pix_confirmado_centavos') + ['diferenca_centavos' => $diferenca],
                motivo: $observacao, unidadeId: $unidadeId, ator: $ator);

            return $f;
        });
    }

    /** Dinheiro que deve estar na gaveta: recebido em dinheiro no dia − devoluções de pagamentos em dinheiro feitas no dia. */
    public function dinheiroEsperado(int $unidadeId, string $data, $doDia = null): int
    {
        $doDia ??= $this->recebimentosDoDia($unidadeId, $data);

        return (int) $doDia->where('meio', 'dinheiro')->where('status', 'registrado')->sum('valor_centavos') - $this->devolvidoEmDinheiro($unidadeId, $data);
    }

    public function devolvidoEmDinheiro(int $unidadeId, string $data): int
    {
        $inicio = Fuso::inicioDoDia($data);

        return (int) Devolucao::where('unidade_id', $unidadeId)->where('status', 'concluida')->where('concluida_em', '>=', $inicio)->where('concluida_em', '<', $inicio->addDay())
            ->whereHas('pagamento', fn ($q) => $q->where('meio', 'dinheiro'))->sum('valor_centavos');
    }

    public function recebimentosDoDia(int $unidadeId, string $data)
    {
        $inicio = Fuso::inicioDoDia($data);

        return Pagamento::with('ordem', 'registradoPor')->where('unidade_id', $unidadeId)
            ->where('recebido_em', '>=', $inicio)->where('recebido_em', '<', $inicio->addDay())->orderBy('recebido_em')->get();
    }

    public function cancelarCobranca(Cobranca $c, string $motivo, ?User $ator = null): void
    {
        if (! in_array($c->status, ['criando', 'pendente', 'falha'], true)) {
            return;
        }
        if ($c->txid) {
            try {
                $this->pix->cancelar($c->txid);
            } catch (\Throwable $e) {
                report($e); // pagamento tardio dessa cobrança cai em revisão na conciliação
            }
        }
        $c->update(['status' => 'cancelada']);
        Auditoria::registrar('cobranca_cancelada', $c->ordem()->first(), depois: ['cobranca_id' => $c->id], motivo: $motivo, ator: $ator, atorTipo: $ator ? 'usuario' : 'sistema');
    }

    /** Nova versão aprovada invalida cobranças abertas da versão anterior. */
    public function invalidarCobrancasDaVersao(int $orcamentoId): void
    {
        foreach (Cobranca::where('orcamento_id', $orcamentoId)->whereIn('status', ['criando', 'pendente', 'falha'])->get() as $c) {
            $this->cancelarCobranca($c, 'versão do orçamento substituída');
        }
    }

    public function expirarVencidas(): int
    {
        $n = 0;
        foreach (Cobranca::where('status', 'pendente')->whereNotNull('txid')->where('expira_em', '<', CarbonImmutable::now())->pluck('txid') as $txid) {
            $this->conciliarCobranca($txid) === 'expirada' && $n++;
        }

        return $n;
    }

    private function exigir(User $ator, string $permissao, Ordem $ordem): void
    {
        if (! $ator->pode($permissao) || ! $ator->acessaUnidade($ordem->unidade_id)) {
            throw new AuthorizationException('Ação não permitida para esta ordem.');
        }
    }
}
