<?php

namespace App\Jobs;

use App\Integracoes\WhatsApp\Provedores;
use App\Models\WhatsappMensagem;
use App\Services\Auditoria;
use App\Services\WhatsApp\Atendimento;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Outbox da Central: envia uma resposta "enviando". Falha transitória → nova tentativa; definitiva → "falhou" visível com "Tentar de novo". */
class EnviarMensagemWhatsApp implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public array $backoff = [30, 120, 600];

    public function __construct(public int $mensagemId) {}

    public function handle(): void
    {
        // Lock evita envio duplo quando job e varredura concorrem.
        $m = DB::transaction(function () {
            $m = WhatsappMensagem::lockForUpdate()->find($this->mensagemId);
            if (! $m || $m->status !== 'enviando' || $m->id_provedor !== null) {
                return null;
            }
            $m->increment('tentativas');

            return $m;
        });
        if (! $m) {
            return;
        }
        $m->load('conversa.numero');
        $numero = $m->conversa->numero;
        $para = $m->conversa->contato_wa_id;
        try {
            $provedor = Provedores::paraNumero($numero);
            $id = match ($m->tipo) {
                'modelo' => $provedor->enviarModelo($numero, $para, (string) $m->modelo, (string) (collect($numero->modelos)->firstWhere('nome', $m->modelo)['idioma'] ?? 'pt_BR')),
                'imagem', 'documento' => $provedor->enviarMidia($numero, $para, Storage::disk('local')->get($m->midia_caminho), (string) $m->midia_mime, (string) $m->midia_nome, $m->texto),
                default => $provedor->enviarTexto($numero, $para, (string) $m->texto),
            };
        } catch (\Throwable $e) {
            $m->update(['ultimo_erro' => mb_substr($e->getMessage(), 0, 300)]);
            $definitiva = ! $this->job || $this->job instanceof SyncJob || $this->attempts() >= $this->tries;
            if ($definitiva) {
                $this->marcarFalha($e->getMessage());
            } else {
                $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
            }

            return;
        }
        WhatsappMensagem::whereKey($m->id)->where('status', 'enviando')->update(['status' => 'enviada', 'id_provedor' => $id, 'enviada_em' => now(), 'ultimo_erro' => null]);
    }

    public function failed(\Throwable $e): void
    {
        $this->marcarFalha($e->getMessage());
    }

    private function marcarFalha(string $erro): void
    {
        DB::transaction(function () use ($erro) {
            $m = WhatsappMensagem::lockForUpdate()->find($this->mensagemId);
            if (! $m || $m->status !== 'enviando') {
                return;
            }
            $m->update(['status' => 'falhou', 'ultimo_erro' => mb_substr($erro, 0, 300)]);
            app(Atendimento::class)->restaurarEspera($m);
            Auditoria::registrar('whatsapp_envio_falhou', $m->conversa, depois: ['mensagem_id' => $m->id, 'tentativas' => $m->tentativas], motivo: $m->ultimo_erro, atorTipo: 'sistema');
        });
    }
}
