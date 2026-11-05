<?php

namespace App\Services;

use App\Enums\Papel;
use App\Enums\StatusOrdem;
use App\Models\Conferencia;
use App\Models\Divergencia;
use App\Models\Ordem;
use App\Models\User;
use App\Support\Fuso;
use App\Support\Normaliza;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Conferência física: veículos presentes × ordens abertas. Divergência indica revisão, não prova fraude. */
class Conferencias
{
    /** Ordens que deveriam ter o veículo no pátio. */
    public function esperadas(int $unidadeId)
    {
        return Ordem::with('veiculo', 'cliente')->where('unidade_id', $unidadeId)
            ->whereIn('status', array_map(fn ($s) => $s->value, StatusOrdem::abertas()))->orderBy('entrada_em')->get();
    }

    /** @param list<int> $presentesIds ordens marcadas como presentes; @param list<string> $placasExtras veículos sem ordem encontrados */
    public function registrar(int $unidadeId, User $ator, array $presentesIds, array $placasExtras, ?string $observacao = null): Conferencia
    {
        if (! $ator->pode('conferir') || ! $ator->acessaUnidade($unidadeId)) {
            throw new AuthorizationException('Sem permissão para conferir esta unidade.');
        }
        // Revisor: gerente da unidade; sem gerente (ex.: Goiânia), o supervisor que a acompanha; por fim, o proprietário.
        $revisor = User::where('unidade_id', $unidadeId)->where('papel', Papel::Gerente)->where('ativo', true)->orderBy('id')->first()
            ?? User::where('papel', Papel::Supervisor)->where('ativo', true)
                ->whereExists(fn ($s) => $s->from('usuario_unidades')->whereColumn('usuario_unidades.user_id', 'users.id')->where('usuario_unidades.unidade_id', $unidadeId))
                ->orderBy('id')->first()
            ?? User::where('papel', Papel::Proprietario)->where('ativo', true)->orderBy('id')->first();

        return DB::transaction(function () use ($unidadeId, $ator, $presentesIds, $placasExtras, $observacao, $revisor) {
            $esperadas = $this->esperadas($unidadeId);
            $placasEsperadas = $esperadas->pluck('veiculo.placa')->filter()->values()->all(); // OS sem veículo é conferida pelo item
            $extras = collect($placasExtras)->map(fn ($p) => Normaliza::placa($p))->filter()->unique();
            foreach ($extras as $p) {
                if (! preg_match(Normaliza::REGEX_PLACA, $p)) {
                    throw ValidationException::withMessages(['placas' => "Placa inválida: {$p}"]);
                }
            }
            $c = Conferencia::create(['unidade_id' => $unidadeId, 'data' => Fuso::exibir(now(), 'Y-m-d'), 'realizada_por' => $ator->id, 'observacao' => $observacao,
                'esperados' => $esperadas->count(), 'presentes' => count(array_intersect($presentesIds, $esperadas->pluck('id')->all())) + $extras->diff($placasEsperadas)->count()]);
            foreach ($esperadas as $o) {
                // Placa digitada como extra que corresponde a ordem aberta também confere.
                if (! in_array($o->id, $presentesIds, true) && ! ($o->veiculo && $extras->contains($o->veiculo->placa))) {
                    $this->divergencia($c, 'ordem_sem_veiculo', $o->veiculo?->placa, $o->id, $revisor ?? $ator);
                }
            }
            foreach ($extras->diff($placasEsperadas) as $p) {
                $this->divergencia($c, 'veiculo_sem_ordem', $p, null, $revisor ?? $ator);
            }
            Auditoria::registrar('conferencia_realizada', $c, depois: $c->only('esperados', 'presentes') + ['divergencias' => Divergencia::where('conferencia_id', $c->id)->count()], motivo: $observacao, unidadeId: $unidadeId, ator: $ator);

            return $c;
        });
    }

    public function resolver(Divergencia $d, User $ator, string $resolucao): void
    {
        if (! $ator->pode('resolver_divergencias') || ! $ator->acessaUnidade($d->unidade_id)) {
            throw new AuthorizationException('A resolução de divergências é feita pelo gerente.');
        }
        DB::transaction(function () use ($d, $ator, $resolucao) {
            $x = Divergencia::lockForUpdate()->findOrFail($d->id);
            if ($x->resolvido_em) {
                throw ValidationException::withMessages(['resolucao' => 'Divergência já resolvida.']);
            }
            $x->update(['resolucao' => $resolucao, 'resolvido_por' => $ator->id, 'resolvido_em' => now()]);
            Auditoria::registrar('divergencia_resolvida', $x, depois: ['tipo' => $x->tipo, 'placa' => $x->placa], motivo: $resolucao, unidadeId: $x->unidade_id, ator: $ator);
        });
    }

    private function divergencia(Conferencia $c, string $tipo, ?string $placa, ?int $ordemId, User $revisor): void
    {
        Divergencia::create(['conferencia_id' => $c->id, 'unidade_id' => $c->unidade_id, 'tipo' => $tipo, 'placa' => $placa, 'ordem_id' => $ordemId, 'revisor_id' => $revisor->id]);
    }
}
