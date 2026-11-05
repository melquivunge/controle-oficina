<?php

namespace App\Services;

use App\Enums\Papel;
use App\Enums\StatusOrdem;
use App\Models\CombinadoPagamento;
use App\Models\Orcamento;
use App\Models\Ordem;
use App\Models\ParcelaPrevista;
use App\Models\User;
use App\Support\Dinheiro;
use App\Support\Fuso;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Combinado de pagamento do saldo (Bloco B). As parcelas guardam só o que foi combinado (valor, vencimento);
 * a situação é derivada dos recebimentos efetivos: pago no combinado = saldo no combinado − saldo atual
 * (Financeiro::resumo já desconta devoluções concluídas e ignora recebimentos em revisão), abatido
 * nas parcelas por ordem de vencimento. O caixa não muda: só conta o que foi recebido.
 */
class Combinados
{
    public const MAX_PARCELAS = 12;

    public function __construct(private Financeiro $financeiro) {}

    /** Quem pode ver combinados/parcelas (mecânico nunca vê valores, mesmo com concessão). */
    public static function podeVer(User $user): bool
    {
        return $user->papel !== Papel::Mecanico && ($user->pode('combinar_pagamento') || $user->pode('receber_manual') || $user->pode('cobrar'));
    }

    public function vigente(Ordem $ordem): ?CombinadoPagamento
    {
        return CombinadoPagamento::with('parcelas')->where('ordem_id', $ordem->id)->where('status', 'vigente')->first();
    }

    /**
     * Situação derivada do combinado vigente, ou null se não houver.
     *
     * @return array{combinado: CombinadoPagamento, parcelas: Collection<int, ParcelaPrevista>, pago: int, em_aberto: int,
     *               vencido: int, proxima: ?ParcelaPrevista, desatualizado: bool, cobre: bool}|null
     */
    public function situacao(Ordem $ordem, ?array $resumo = null, ?CombinadoPagamento $combinado = null): ?array
    {
        $combinado ??= $this->vigente($ordem);
        if (! $combinado) {
            return null;
        }
        $resumo ??= $this->financeiro->resumo($ordem);
        $aprovadoId = Orcamento::where('ordem_id', $ordem->id)->where('status', 'aprovado')->value('id');
        $total = (int) $combinado->saldo_centavos;
        $pago = max(0, min($total, $total - (int) $resumo['saldo']));
        $hoje = Fuso::exibir(now(), 'Y-m-d');

        $restante = $pago;
        $parcelas = $combinado->parcelas->sortBy([['vencimento', 'asc'], ['sequencia', 'asc']])->values()->each(function (ParcelaPrevista $p) use (&$restante, $hoje) {
            $p->pago_centavos = min((int) $p->valor_centavos, $restante);
            $restante -= $p->pago_centavos;
            $p->situacao = match (true) {
                $p->pago_centavos >= $p->valor_centavos => 'paga',
                $p->vencimento->format('Y-m-d') < $hoje => 'vencida',
                $p->pago_centavos > 0 => 'parcial',
                default => 'aberta',
            };
        });
        $desatualizado = (int) $combinado->orcamento_id !== (int) $aprovadoId || (int) $resumo['saldo'] > $total;

        return [
            'combinado' => $combinado, 'parcelas' => $parcelas, 'pago' => $pago, 'em_aberto' => $total - $pago,
            'vencido' => (int) $parcelas->where('situacao', 'vencida')->sum(fn ($p) => $p->emAbertoCentavos()),
            'proxima' => $parcelas->first(fn ($p) => $p->situacao !== 'paga'),
            'desatualizado' => $desatualizado,
            // Cobre todo o saldo: mesma versão aprovada e saldo atual dentro do combinado.
            'cobre' => ! $desatualizado && (int) $resumo['saldo'] > 0,
        ];
    }

    /**
     * Registra (ou substitui, com motivo) o combinado do saldo. Soma das parcelas = saldo atual, verificado sob lock.
     *
     * @param  array<int, array{valor_centavos:int, vencimento:string, meio_previsto?:?string, observacao?:?string}>  $parcelas
     */
    public function registrar(Ordem $ordem, User $ator, array $parcelas, ?string $motivo = null): CombinadoPagamento
    {
        $this->exigir($ator, $ordem);

        return DB::transaction(function () use ($ordem, $ator, $parcelas, $motivo) {
            $o = Ordem::lockForUpdate()->findOrFail($ordem->id);
            $orc = Orcamento::where('ordem_id', $o->id)->where('status', 'aprovado')->first();
            if (! $orc || ! in_array($o->status, StatusOrdem::recebiveis(), true)) {
                throw ValidationException::withMessages(['parcelas' => 'Só é possível combinar pagamento de ordem com orçamento aprovado e não cancelada.']);
            }
            $saldo = $this->financeiro->resumo($o)['saldo'];
            if ($saldo <= 0) {
                throw ValidationException::withMessages(['parcelas' => 'Esta ordem não tem saldo a receber.']);
            }
            $parcelas = $this->validarParcelas($parcelas, $saldo);

            $anterior = CombinadoPagamento::where('ordem_id', $o->id)->where('status', 'vigente')->lockForUpdate()->first();
            if ($anterior) {
                if (mb_strlen(trim((string) $motivo)) < 5) {
                    throw ValidationException::withMessages(['motivo_combinado' => 'Informe o motivo do ajuste do combinado.']);
                }
                $anterior->update(['status' => 'substituido', 'motivo_encerramento' => trim($motivo), 'encerrado_por' => $ator->id, 'encerrado_em' => now()]);
            }
            $c = CombinadoPagamento::create(['ordem_id' => $o->id, 'unidade_id' => $o->unidade_id, 'orcamento_id' => $orc->id,
                'saldo_centavos' => $saldo, 'status' => 'vigente', 'criado_por' => $ator->id]);
            foreach ($parcelas as $i => $p) {
                $c->parcelas()->create($p + ['sequencia' => $i + 1, 'ordem_id' => $o->id, 'unidade_id' => $o->unidade_id]);
            }
            $resumoParcelas = array_map(fn ($p) => ['valor_centavos' => $p['valor_centavos'], 'vencimento' => $p['vencimento'], 'meio_previsto' => $p['meio_previsto']], $parcelas);
            Auditoria::registrar($anterior ? 'combinado_ajustado' : 'combinado_registrado', $o,
                antes: $anterior ? ['combinado_id' => $anterior->id, 'parcelas' => $anterior->parcelas()->get(['valor_centavos', 'vencimento'])->toArray()] : null,
                depois: ['combinado_id' => $c->id, 'saldo_centavos' => $saldo, 'parcelas' => $resumoParcelas], motivo: $anterior ? trim($motivo) : null, ator: $ator);

            return $c->load('parcelas');
        });
    }

    public function cancelar(Ordem $ordem, User $ator, string $motivo): void
    {
        $this->exigir($ator, $ordem);
        if (mb_strlen(trim($motivo)) < 5) {
            throw ValidationException::withMessages(['motivo_combinado' => 'Informe o motivo do cancelamento do combinado.']);
        }
        DB::transaction(function () use ($ordem, $ator, $motivo) {
            $o = Ordem::lockForUpdate()->findOrFail($ordem->id);
            $c = CombinadoPagamento::where('ordem_id', $o->id)->where('status', 'vigente')->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['motivo_combinado' => 'Não há combinado vigente nesta ordem.']);
            $c->update(['status' => 'cancelado', 'motivo_encerramento' => trim($motivo), 'encerrado_por' => $ator->id, 'encerrado_em' => now()]);
            Auditoria::registrar('combinado_cancelado', $o, depois: ['combinado_id' => $c->id], motivo: trim($motivo), ator: $ator);
        });
    }

    /**
     * Parcelas vencidas e não pagas de combinados vigentes nas unidades visíveis ao usuário
     * (vazio para quem não vê valores). Cada item traz pago_centavos/situacao e a ordem carregada.
     *
     * @return Collection<int, ParcelaPrevista>
     */
    public function vencidas(User $user): Collection
    {
        if (! self::podeVer($user)) {
            return collect();
        }
        $hoje = Fuso::exibir(now(), 'Y-m-d');
        $ordemIds = ParcelaPrevista::query()
            ->when(! $user->visaoGlobal(), fn ($q) => $q->whereIn('unidade_id', $user->unidadeIds()))
            ->where('vencimento', '<', $hoje)
            ->whereHas('combinado', fn ($q) => $q->where('status', 'vigente'))
            ->distinct()->limit(500)->pluck('ordem_id');

        return Ordem::whereIn('id', $ordemIds)->get()->flatMap(function (Ordem $o) {
            $s = $this->situacao($o);

            return $s ? $s['parcelas']->where('situacao', 'vencida')->each(fn ($p) => $p->setRelation('ordem', $o)) : [];
        })->sortBy(fn ($p) => $p->vencimento->format('Y-m-d'))->values();
    }

    /** @return array<int, array{valor_centavos:int, vencimento:string, meio_previsto:?string, observacao:?string}> */
    private function validarParcelas(array $parcelas, int $saldo): array
    {
        $hoje = Fuso::exibir(now(), 'Y-m-d');
        if ($parcelas === [] || count($parcelas) > self::MAX_PARCELAS) {
            throw ValidationException::withMessages(['parcelas' => 'Informe de 1 a '.self::MAX_PARCELAS.' parcelas.']);
        }
        $limpas = [];
        foreach (array_values($parcelas) as $i => $p) {
            $n = $i + 1;
            $valor = (int) ($p['valor_centavos'] ?? 0);
            $venc = (string) ($p['vencimento'] ?? '');
            $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $venc);
            $meio = ($p['meio_previsto'] ?? null) ?: null;
            if ($valor <= 0) {
                throw ValidationException::withMessages(['parcelas' => "Parcela {$n}: o valor deve ser maior que zero."]);
            }
            if (! $data || $data->format('Y-m-d') !== $venc) {
                throw ValidationException::withMessages(['parcelas' => "Parcela {$n}: informe a data de vencimento."]);
            }
            if ($venc < $hoje) {
                throw ValidationException::withMessages(['parcelas' => "Parcela {$n}: o vencimento não pode ser anterior a hoje."]);
            }
            if ($meio !== null && ! array_key_exists($meio, ParcelaPrevista::MEIOS)) {
                throw ValidationException::withMessages(['parcelas' => "Parcela {$n}: meio previsto inválido."]);
            }
            $obs = trim((string) ($p['observacao'] ?? ''));
            $limpas[] = ['valor_centavos' => $valor, 'vencimento' => $venc, 'meio_previsto' => $meio, 'observacao' => $obs === '' ? null : mb_substr($obs, 0, 300)];
        }
        $soma = array_sum(array_column($limpas, 'valor_centavos'));
        if ($soma !== $saldo) {
            throw ValidationException::withMessages(['parcelas' => 'A soma das parcelas ('.Dinheiro::formatar($soma).') deve ser igual ao saldo a receber ('.Dinheiro::formatar($saldo).').']);
        }

        return $limpas;
    }

    private function exigir(User $ator, Ordem $ordem): void
    {
        if ($ator->papel === Papel::Mecanico || ! $ator->pode('combinar_pagamento') || ! $ator->acessaUnidade($ordem->unidade_id)) {
            throw new AuthorizationException('Ação não permitida para esta ordem.');
        }
    }
}
