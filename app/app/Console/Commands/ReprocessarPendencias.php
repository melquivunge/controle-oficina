<?php

namespace App\Console\Commands;

use App\Jobs\ProcessarDocumentoFiscal;
use App\Models\FiscalDocument;
use Illuminate\Console\Command;

/** Varredura do outbox: despacha mensagens e documentos fiscais pendentes cujo job se perdeu (ex.: Redis fora do ar). */
class ReprocessarPendencias extends Command
{
    protected $signature = 'oficina:reprocessar-pendencias';

    protected $description = 'Redespacha mensagens e documentos fiscais pendentes há mais de 5 minutos.';

    public function handle(): int
    {
        $m = app(\App\Services\Mensagens::class)->redespacharPendentes();
        $f = FiscalDocument::whereIn('status', ['pendente', 'processando'])->where('updated_at', '<', now()->subMinutes(5))->pluck('id');
        $f->each(fn ($id) => ProcessarDocumentoFiscal::dispatch($id));
        $this->info("pendências redespachadas: mensagens={$m} fiscal={$f->count()}");

        return self::SUCCESS;
    }
}
