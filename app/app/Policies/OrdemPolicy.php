<?php

namespace App\Policies;

use App\Enums\Papel;
use App\Models\Ordem;
use App\Models\User;

/** Toda decisão verifica a unidade da ordem; mecânico só atua na própria fila. */
class OrdemPolicy
{
    public function view(User $user, Ordem $ordem): bool
    {
        if (! $user->acessaUnidade($ordem->unidade_id)) {
            return false;
        }

        return $user->papel !== Papel::Mecanico || in_array($ordem->mecanico_id, [null, $user->id], true);
    }

    public function atribuir(User $user, Ordem $ordem): bool
    {
        return $user->pode('registrar_entrada') && $user->acessaUnidade($ordem->unidade_id);
    }

    public function diagnosticar(User $user, Ordem $ordem): bool
    {
        return $user->pode('executar') && $this->view($user, $ordem)
            && ($user->papel !== Papel::Mecanico || in_array($ordem->mecanico_id, [null, $user->id], true));
    }

    public function orcar(User $user, Ordem $ordem): bool
    {
        return $user->pode('orcar') && $user->acessaUnidade($ordem->unidade_id) && ! $ordem->status->encerrada();
    }

    /** Data de entrega prevista: somente quem orça (gerente/proprietário), na unidade, com a ordem aberta. */
    public function definirPrazo(User $user, Ordem $ordem): bool
    {
        return $user->pode('orcar') && $user->acessaUnidade($ordem->unidade_id) && ! $ordem->status->encerrada();
    }

    /**
     * Acompanhar o cliente sobre o orçamento já fechado: gerar/revogar link e registrar a decisão informada pessoalmente.
     * Quem orça ou quem cobra (recepção). Não permite montar/alterar/enviar orçamento.
     */
    public function acompanharCliente(User $user, Ordem $ordem): bool
    {
        return ($user->pode('orcar') || $user->pode('cobrar')) && $user->acessaUnidade($ordem->unidade_id) && ! $ordem->status->encerrada();
    }

    /** Vendedor da OS: quem orça (gerente/proprietário) na unidade; ordem não cancelada. */
    public function definirVendedor(User $user, Ordem $ordem): bool
    {
        return $user->pode('orcar') && $user->acessaUnidade($ordem->unidade_id) && $ordem->status !== \App\Enums\StatusOrdem::Cancelado;
    }

    /** OS impressa: quem vê valores (proprietário/gerente/recepção), na unidade; mecânico nunca. */
    public function imprimir(User $user, Ordem $ordem): bool
    {
        return $user->pode('ver_valores') && $this->view($user, $ordem);
    }

    public function anexar(User $user, Ordem $ordem): bool
    {
        return $this->view($user, $ordem) && ! $ordem->status->encerrada()
            && ($user->pode('registrar_entrada') || $user->pode('executar'));
    }

    public function cancelar(User $user, Ordem $ordem): bool
    {
        return $user->pode('cancelar_ordem') && $user->acessaUnidade($ordem->unidade_id) && ! $ordem->status->encerrada();
    }
}
