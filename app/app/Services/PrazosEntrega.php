<?php

namespace App\Services;

use App\Enums\StatusOrdem;
use App\Models\Ordem;
use App\Models\User;
use App\Support\DiasUteis;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Data de entrega prevista (dias úteis). Definida só por quem orça (gerente/proprietário); toda alteração é auditada. */
class PrazosEntrega
{
    /** Situações em que o prazo já não pode atrasar: pronta, entregue ou cancelada. */
    public const SEM_ATRASO = [StatusOrdem::ProntoParaEntrega, StatusOrdem::Entregue, StatusOrdem::Cancelado];

    /** Informe $diasUteis (calcula a partir de hoje) OU $data (Y-m-d, precisa ser dia útil, hoje ou depois). */
    public function definir(Ordem $ordem, User $ator, ?int $diasUteis = null, ?string $data = null): Ordem
    {
        if (! $ator->can('definirPrazo', $ordem)) {
            throw new AuthorizationException('Somente gerente ou proprietário define a data de entrega.');
        }
        $prazo = $this->calcular($diasUteis, $data, $ordem->unidade_id);

        return DB::transaction(function () use ($ordem, $ator, $prazo, $diasUteis) {
            $o = Ordem::lockForUpdate()->findOrFail($ordem->id);
            if ($o->status->encerrada()) {
                throw ValidationException::withMessages(['prazo' => 'Ordem encerrada: a data de entrega não pode mais ser alterada.']);
            }
            $antes = $o->entrega_prevista_em?->format('Y-m-d');
            if ($antes === $prazo->format('Y-m-d')) {
                return $o;
            }
            $o->forceFill([
                'entrega_prevista_em' => $prazo->format('Y-m-d'),
                'entrega_prevista_por' => $ator->id,
                'entrega_prevista_definida_em' => now(),
            ])->save();
            Auditoria::registrar('prazo_entrega_definido', $o, ['entrega_prevista_em' => $antes],
                ['entrega_prevista_em' => $prazo->format('Y-m-d'), 'dias_uteis' => $diasUteis], ator: $ator);

            return $o;
        });
    }

    /** $unidadeId: considera também os feriados estaduais/municipais da unidade da OS (feriados_unidade). */
    public function calcular(?int $diasUteis, ?string $data, ?int $unidadeId = null): CarbonImmutable
    {
        $max = (int) config('oficina.prazo_entrega_max_dias_uteis', 120);
        if (($diasUteis === null) === ($data === null || $data === '')) {
            throw ValidationException::withMessages(['prazo_dias' => 'Informe o prazo em dias úteis ou a data de entrega.']);
        }
        if ($diasUteis !== null) {
            if ($diasUteis < 1 || $diasUteis > $max) {
                throw ValidationException::withMessages(['prazo_dias' => "Prazo entre 1 e {$max} dias úteis."]);
            }

            return DiasUteis::somar(DiasUteis::hoje(), $diasUteis, $unidadeId);
        }
        $d = CarbonImmutable::createFromFormat('!Y-m-d', $data, \App\Support\Fuso::LOCAL);
        if (! $d || $d->format('Y-m-d') !== $data) {
            throw ValidationException::withMessages(['prazo_data' => 'Data inválida.']);
        }
        if ($d->lt(DiasUteis::hoje())) {
            throw ValidationException::withMessages(['prazo_data' => 'A data de entrega não pode ser no passado.']);
        }
        if (! DiasUteis::ehUtil($d, $unidadeId)) {
            throw ValidationException::withMessages(['prazo_data' => 'Escolha um dia útil (segunda a sexta, fora de feriado).']);
        }
        if (DiasUteis::entre(DiasUteis::hoje(), $d, $unidadeId) > $max) {
            throw ValidationException::withMessages(['prazo_data' => "Prazo máximo de {$max} dias úteis."]);
        }

        return $d;
    }

    /** Prazo vencido (antes de hoje, no fuso local) e a ordem ainda não está pronta/entregue/cancelada. */
    public static function atrasada(Ordem $ordem): bool
    {
        return $ordem->entrega_prevista_em !== null
            && ! in_array($ordem->status, self::SEM_ATRASO, true)
            && $ordem->entrega_prevista_em->format('Y-m-d') < DiasUteis::hoje()->format('Y-m-d');
    }

    /** Consulta das atrasadas visíveis ao usuário (unidade e, para mecânico, a própria fila). */
    public function consultaAtrasadas(User $user): Builder
    {
        return Ordem::visiveisPara($user)
            ->whereNotNull('entrega_prevista_em')
            ->where('entrega_prevista_em', '<', DiasUteis::hoje()->format('Y-m-d'))
            ->whereNotIn('status', array_map(fn (StatusOrdem $s) => $s->value, self::SEM_ATRASO));
    }

    /**
     * Ordens com entrega atrasada que o usuário pode ver, mais atrasadas primeiro (para o sino de notificações).
     *
     * @return Collection<int, Ordem>
     */
    public function atrasadas(User $user, int $limite = 50): Collection
    {
        return $this->consultaAtrasadas($user)->with('veiculo', 'unidade')
            ->orderBy('entrega_prevista_em')->orderBy('entrada_em')->limit($limite)->get();
    }
}
