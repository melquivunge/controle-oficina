<?php

namespace App\Jobs;

use App\Integracoes\Mensagens\Mensageiro;
use App\Models\MessageDelivery;
use App\Services\Auditoria;
use App\Services\Mensagens;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class EnviarMensagem implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public array $backoff = [30, 120, 600];

    public function __construct(public int $mensagemId) {}

    public function handle(Mensageiro $canal): void
    {
        // Lock evita envio duplo quando job e varredura concorrem.
        $m = DB::transaction(function () {
            $m = MessageDelivery::lockForUpdate()->find($this->mensagemId);
            if (! $m || $m->status !== 'pendente') {
                return null;
            }
            $m->increment('tentativas');

            return $m;
        });
        if (! $m) {
            return;
        }
        // OS enviada por WhatsApp aponta para a versão somente leitura da OS no portal (/c/{token}/os).
        $link = $m->segredo_link ? route($m->tipo === 'documento_os' ? 'portal.impressao' : 'portal.show', $m->segredo_link) : url('/');
        try {
            $id = $canal->enviar($m->destino, str_replace(Mensagens::MARCADOR_LINK, $link, $m->conteudo));
        } catch (\Throwable $e) {
            $m->update(['ultimo_erro' => mb_substr($e->getMessage(), 0, 300)]);
            throw $e;
        }
        $m->update(['status' => 'enviado', 'id_provedor' => $id, 'enviado_em' => now(), 'segredo_link' => null, 'ultimo_erro' => null]);
    }

    public function failed(\Throwable $e): void
    {
        $m = MessageDelivery::find($this->mensagemId);
        if ($m && $m->status === 'pendente') {
            $m->update(['status' => 'falhou', 'segredo_link' => null, 'ultimo_erro' => mb_substr($e->getMessage(), 0, 300)]);
            Auditoria::registrar('mensagem_falhou', \App\Models\Ordem::find($m->ordem_id), depois: ['mensagem_id' => $m->id, 'tipo' => $m->tipo, 'tentativas' => $m->tentativas], motivo: $m->ultimo_erro, atorTipo: 'sistema');
        }
    }
}
