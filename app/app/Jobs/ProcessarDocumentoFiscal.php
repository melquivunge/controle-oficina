<?php

namespace App\Jobs;

use App\Models\FiscalDocument;
use App\Services\Auditoria;
use App\Services\Fiscal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessarDocumentoFiscal implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public array $backoff = [30, 120, 600];

    public function __construct(public int $documentoId) {}

    public function handle(Fiscal $fiscal): void
    {
        $fiscal->processar($this->documentoId);
    }

    public function failed(\Throwable $e): void
    {
        $doc = FiscalDocument::find($this->documentoId);
        Auditoria::registrar('fiscal_falha_processamento', $doc?->ordem, depois: ['documento_id' => $this->documentoId, 'tentativas' => $doc?->tentativas], motivo: mb_substr($e->getMessage(), 0, 200), atorTipo: 'sistema');
    }
}
