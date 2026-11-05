<?php

namespace App\Services;

use App\Enums\StatusOrdem;
use App\Models\Orcamento;
use App\Models\OrcamentoItem;
use App\Models\Ordem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Execução: somente itens da versão aprovada vigente; extras aguardam nova aprovação. */
class Execucao
{
    public function iniciar(Ordem $ordem, User $ator): void
    {
        $this->autorizar($ator, $ordem);
        app(Ordens::class)->transicionar($ordem, StatusOrdem::EmExecucao, $ator, antesDeSalvar: function (Ordem $o) {
            if (! Orcamento::where('ordem_id', $o->id)->where('status', 'aprovado')->exists()) {
                throw ValidationException::withMessages(['status' => 'Não há orçamento aprovado para executar.']);
            }
        });
    }

    public function concluirItem(Ordem $ordem, int $itemId, User $ator): void
    {
        $this->autorizar($ator, $ordem);
        DB::transaction(function () use ($ordem, $itemId, $ator) {
            $o = Ordem::lockForUpdate()->findOrFail($ordem->id);
            $item = OrcamentoItem::whereKey($itemId)->whereHas('orcamento', fn ($q) => $q->where('ordem_id', $o->id)->where('status', 'aprovado'))->lockForUpdate()->first();
            if ($o->status !== StatusOrdem::EmExecucao) {
                throw ValidationException::withMessages(['item' => 'Inicie a execução antes de concluir serviços.']);
            }
            if (! $item) {
                throw ValidationException::withMessages(['item' => 'Este serviço não está autorizado pelo cliente.']);
            }
            if ($item->concluido_em) {
                return;
            }
            $item->update(['concluido_em' => now(), 'concluido_por' => $ator->id]);
            Auditoria::registrar('servico_concluido', $o, depois: ['item_id' => $item->id, 'descricao' => $item->descricao], ator: $ator);
        });
    }

    public function marcarPronto(Ordem $ordem, User $ator, ?string $resumo = null): void
    {
        $this->autorizar($ator, $ordem);
        DB::transaction(fn () => $this->pronto($ordem, $ator, $resumo));
    }

    private function pronto(Ordem $ordem, User $ator, ?string $resumo): void
    {
        $o = app(Ordens::class)->transicionar($ordem, StatusOrdem::ProntoParaEntrega, $ator, $resumo, function (Ordem $o) {
            $aprovado = Orcamento::where('ordem_id', $o->id)->where('status', 'aprovado')->first();
            if (! $aprovado || $aprovado->itens()->whereNull('concluido_em')->exists()) {
                throw ValidationException::withMessages(['status' => 'Conclua todos os serviços autorizados antes de liberar para entrega.']);
            }
            if (Orcamento::where('ordem_id', $o->id)->where('status', 'enviado')->exists()) {
                throw ValidationException::withMessages(['status' => 'Há uma versão do orçamento aguardando decisão do cliente.']);
            }
        });
        app(Mensagens::class)->enfileirar($o, 'conclusao', app(Orcamentos::class)->novoLink($o, $ator));
    }

    private function autorizar(User $ator, Ordem $ordem): void
    {
        if (! $ator->can('diagnosticar', $ordem)) {
            throw new AuthorizationException('Ação não permitida para esta ordem.');
        }
    }
}
