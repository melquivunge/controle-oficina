<?php

namespace App\Jobs;

use App\Models\IntegrationEvent;
use App\Services\Auditoria;
use App\Services\Financeiro;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Processa notificação já autenticada e deduplicada; a verdade vem da consulta ao provedor. */
class ProcessarEventoPix implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [10, 60, 300, 900];

    public function __construct(public int $eventoId) {}

    public function handle(Financeiro $financeiro): void
    {
        $evento = IntegrationEvent::findOrFail($this->eventoId);
        if ($evento->processado_em) {
            return;
        }
        $resultado = $financeiro->conciliarCobranca((string) ($evento->payload['txid'] ?? ''));
        $evento->update(['processado_em' => now(), 'resultado' => $resultado, 'erro' => null]);
    }

    public function failed(\Throwable $e): void
    {
        IntegrationEvent::whereKey($this->eventoId)->update(['erro' => mb_substr($e->getMessage(), 0, 500)]);
        Auditoria::registrar('integracao_falhou', null, depois: ['integration_event_id' => $this->eventoId, 'job' => 'ProcessarEventoPix'], motivo: mb_substr($e->getMessage(), 0, 200), atorTipo: 'sistema');
    }
}
