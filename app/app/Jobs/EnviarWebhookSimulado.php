<?php

namespace App\Jobs;

use App\Integracoes\Pix\SimuladorPix;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** SIMULAÇÃO: o "banco" notifica o sistema por HTTP com assinatura, como faria um provedor real. */
class EnviarWebhookSimulado implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [5, 30, 120];

    public function __construct(public string $txid, public ?int $valorCentavos = null) {}

    public function handle(SimuladorPix $simulador): void
    {
        $evento = $simulador->simularPagamento($this->txid, $this->valorCentavos);
        $corpo = json_encode(['evento_id' => $evento['evento_id'], 'tipo' => 'pix.pago', 'txid' => $this->txid]);
        $r = Http::withBody($corpo, 'application/json')->withHeaders(['X-Assinatura' => $simulador->assinar($corpo)])
            ->timeout(10)->post(config('oficina.pix.webhook_url_interna'));
        if (! $r->successful()) {
            throw new RuntimeException("Webhook simulado recusado: HTTP {$r->status()}");
        }
    }
}
