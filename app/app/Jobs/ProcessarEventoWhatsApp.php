<?php

namespace App\Jobs;

use App\Models\IntegrationEvent;
use App\Services\Auditoria;
use App\Services\WhatsApp\Recebimento;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;

/** Processa evento de WhatsApp já autenticado e deduplicado. */
class ProcessarEventoWhatsApp implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [10, 60, 300, 900];

    public function __construct(public int $eventoId) {}

    public function handle(Recebimento $recebimento): void
    {
        $evento = IntegrationEvent::findOrFail($this->eventoId);
        if ($evento->processado_em) {
            return;
        }
        $resultado = $recebimento->processar($evento);
        // Status pode chegar antes de o envio gravar o id do provedor: na fila real, tenta de novo mais tarde.
        if ($resultado === 'mensagem_desconhecida' && $this->job && ! $this->job instanceof SyncJob && $this->attempts() < $this->tries) {
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);

            return;
        }
        $evento->update(['processado_em' => now(), 'resultado' => $resultado, 'erro' => null]);
    }

    public function failed(\Throwable $e): void
    {
        IntegrationEvent::whereKey($this->eventoId)->update(['erro' => mb_substr($e->getMessage(), 0, 500)]);
        Auditoria::registrar('integracao_falhou', null, depois: ['integration_event_id' => $this->eventoId, 'job' => 'ProcessarEventoWhatsApp'], motivo: mb_substr($e->getMessage(), 0, 200), atorTipo: 'sistema');
    }
}
