<?php

namespace App\Services;

use App\Enums\Papel;
use App\Enums\StatusOrdem;
use App\Models\Ordem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Transições da ordem. Sempre com lock da linha e validação do estado atual no servidor. */
class Ordens
{
    /** $ator null = ação do cliente pelo link. */
    public function transicionar(Ordem $ordem, StatusOrdem $destino, ?User $ator, ?string $motivo = null, ?callable $antesDeSalvar = null): Ordem
    {
        return DB::transaction(function () use ($ordem, $destino, $ator, $motivo, $antesDeSalvar) {
            $atual = Ordem::lockForUpdate()->findOrFail($ordem->id);
            if (! $atual->status->podeIrPara($destino)) {
                throw ValidationException::withMessages(['status' => "Não é possível passar de “{$atual->status->rotulo()}” para “{$destino->rotulo()}”. Atualize a página."]);
            }
            $de = $atual->status;
            $atual->status = $destino;
            if ($antesDeSalvar) {
                $antesDeSalvar($atual);
            }
            $atual->save();
            Auditoria::registrar('ordem_status', $atual, ['status' => $de->value], ['status' => $destino->value], $motivo, ator: $ator, atorTipo: $ator ? 'usuario' : 'cliente');
            $ordem->setRawAttributes($atual->getAttributes(), true);

            return $atual;
        });
    }

    public function atribuirMecanico(Ordem $ordem, User $mecanico, User $ator): void
    {
        $this->autorizar($ator, 'atribuir', $ordem);
        if ($mecanico->papel !== Papel::Mecanico || ! $mecanico->ativo || $mecanico->unidade_id !== $ordem->unidade_id) {
            throw ValidationException::withMessages(['mecanico_id' => 'Escolha um mecânico ativo desta unidade.']);
        }
        DB::transaction(function () use ($ordem, $mecanico, $ator) {
            $atual = Ordem::lockForUpdate()->findOrFail($ordem->id);
            if ($atual->status->encerrada()) {
                throw ValidationException::withMessages(['mecanico_id' => 'Ordem encerrada não pode ser reatribuída.']);
            }
            $antes = ['mecanico_id' => $atual->mecanico_id];
            $atual->mecanico_id = $mecanico->id;
            $atual->save();
            Auditoria::registrar('ordem_atribuida', $atual, $antes, ['mecanico_id' => $mecanico->id], ator: $ator);
            $ordem->setRawAttributes($atual->getAttributes(), true);
        });
    }

    /** Mecânico assume ordem sem responsável e inicia diagnóstico. */
    public function iniciarDiagnostico(Ordem $ordem, User $ator): void
    {
        $this->autorizar($ator, 'diagnosticar', $ordem);
        $this->transicionar($ordem, StatusOrdem::Diagnostico, $ator, antesDeSalvar: function (Ordem $o) use ($ator) {
            if ($o->mecanico_id !== null && $o->mecanico_id !== $ator->id && $ator->papel === Papel::Mecanico) {
                throw ValidationException::withMessages(['status' => 'Esta ordem já foi assumida por outro mecânico.']);
            }
            if ($o->mecanico_id === null && $ator->papel === Papel::Mecanico) {
                $o->mecanico_id = $ator->id;
            }
        });
    }

    public function registrarDiagnostico(Ordem $ordem, string $texto, User $ator): void
    {
        $this->autorizar($ator, 'diagnosticar', $ordem);
        DB::transaction(function () use ($ordem, $texto, $ator) {
            $atual = Ordem::lockForUpdate()->findOrFail($ordem->id);
            if (! in_array($atual->status, [StatusOrdem::Diagnostico, StatusOrdem::AguardandoAprovacao, StatusOrdem::EmExecucao], true)) {
                throw ValidationException::withMessages(['diagnostico' => 'O diagnóstico só pode ser registrado com a ordem em diagnóstico ou execução.']);
            }
            $antes = ['diagnostico' => $atual->diagnostico];
            $atual->diagnostico = $texto;
            $atual->save();
            Auditoria::registrar('ordem_diagnostico', $atual, $antes, ['diagnostico' => $texto], ator: $ator);
            $ordem->setRawAttributes($atual->getAttributes(), true);
        });
    }

    /** Cancelamento não estorna valores (ciclo de pagamento é independente). */
    public function cancelar(Ordem $ordem, string $motivo, User $ator): void
    {
        $this->autorizar($ator, 'cancelar', $ordem);
        $this->transicionar($ordem, StatusOrdem::Cancelado, $ator, $motivo, fn (Ordem $o) => $o->cancelamento_motivo = $motivo);
    }

    private function autorizar(User $ator, string $acao, Ordem $ordem): void
    {
        if (! $ator->can($acao, $ordem)) {
            throw new AuthorizationException('Ação não permitida para esta ordem.');
        }
    }
}
