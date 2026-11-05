<?php

namespace App\Services;

use App\Models\Orcamento;
use App\Models\Ordem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vendedor da OS: usuário que orça (gerente/proprietário) com acesso à unidade da ordem.
 * Padrão: quem enviou o orçamento (ou, na aprovação, quem registrou se ainda vazio); alterável por quem tem `orcar`.
 */
class Vendedores
{
    public static function elegivel(?User $u, Ordem $ordem): bool
    {
        return $u !== null && $u->pode('orcar') && $u->acessaUnidade($ordem->unidade_id);
    }

    /** @return Collection<int, User> */
    public function opcoes(Ordem $ordem): Collection
    {
        return User::where('ativo', true)->orderBy('name')->get()->filter(fn (User $u) => self::elegivel($u, $ordem))->values();
    }

    /** Chamado quando uma versão do orçamento passa a enviada/aprovada (observer do modelo). Não sobrescreve vendedor já definido. */
    public function definirPadrao(Orcamento $orcamento): void
    {
        $ordem = Ordem::lockForUpdate()->find($orcamento->ordem_id);
        if (! $ordem || $ordem->vendedor_id) {
            return;
        }
        // Aprovação pelo link do cliente nunca herda um usuário logado no mesmo navegador.
        $logado = app()->runningInConsole() || ! request()->routeIs('portal.*') ? Auth::user() : null;
        $candidato = collect([$logado, $orcamento->criado_por ? User::find($orcamento->criado_por) : null])
            ->first(fn (?User $u) => self::elegivel($u, $ordem));
        if (! $candidato) {
            return;
        }
        $ordem->forceFill(['vendedor_id' => $candidato->id])->save();
        Auditoria::registrar('vendedor_definido', $ordem, ['vendedor_id' => null], ['vendedor_id' => $candidato->id, 'vendedor' => $candidato->name, 'origem' => 'orcamento_'.$orcamento->status, 'versao' => $orcamento->versao]);
    }

    public function alterar(Ordem $ordem, User $ator, int $vendedorId, ?string $motivo = null): Ordem
    {
        if (! $ator->pode('orcar') || ! $ator->acessaUnidade($ordem->unidade_id)) {
            throw new AuthorizationException('Somente gerente/proprietário da unidade altera o vendedor.');
        }

        return DB::transaction(function () use ($ordem, $ator, $vendedorId, $motivo) {
            $o = Ordem::lockForUpdate()->findOrFail($ordem->id);
            if ($o->status === \App\Enums\StatusOrdem::Cancelado) {
                throw ValidationException::withMessages(['vendedor_id' => 'Ordem cancelada: o vendedor não pode ser alterado.']);
            }
            $vendedor = User::find($vendedorId);
            if (! $vendedor || ! $vendedor->ativo || ! self::elegivel($vendedor, $o)) {
                throw ValidationException::withMessages(['vendedor_id' => 'Escolha um gerente ou proprietário ativo desta unidade.']);
            }
            if ($o->vendedor_id === $vendedor->id) {
                return $o;
            }
            $antes = ['vendedor_id' => $o->vendedor_id];
            $o->forceFill(['vendedor_id' => $vendedor->id])->save();
            Auditoria::registrar('vendedor_definido', $o, $antes, ['vendedor_id' => $vendedor->id, 'vendedor' => $vendedor->name, 'origem' => 'manual'], $motivo, ator: $ator);

            return $o;
        });
    }
}
