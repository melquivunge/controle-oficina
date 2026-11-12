<?php

namespace App\Jobs;

use App\Integracoes\WhatsApp\MidiaRecusada;
use App\Integracoes\WhatsApp\Provedores;
use App\Models\WhatsappMensagem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Baixa a mídia recebida para o disco privado; tipo conferido pelo conteúdo (não pelo que o remetente declara) e tamanho limitado. */
class BaixarMidiaWhatsApp implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public array $backoff = [30, 120, 600];

    /** Tipos aceitos (conteúdo detectado) → extensão. */
    public const TIPOS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'audio/ogg' => 'ogg', 'application/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac', 'audio/amr' => 'amr',
        'video/mp4' => 'mp4', 'video/3gpp' => '3gp', 'video/quicktime' => 'mov', 'video/webm' => 'webm',
        'application/pdf' => 'pdf', 'text/plain' => 'txt',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    public function __construct(public int $mensagemId) {}

    public function handle(): void
    {
        $m = WhatsappMensagem::with('numero')->find($this->mensagemId);
        if (! $m || $m->midia_status !== 'pendente' || ! $m->midia_id_provedor) {
            return;
        }
        $limite = (int) config('oficina.whatsapp.midia_max_bytes');
        try {
            $r = Provedores::paraNumero($m->numero)->baixarMidia($m->numero, $m->midia_id_provedor, $limite);
            $conteudo = $r['conteudo'];
            if (strlen($conteudo) > $limite) {
                throw new MidiaRecusada('Arquivo maior que o limite permitido.');
            }
            $detectado = (new \finfo(FILEINFO_MIME_TYPE))->buffer($conteudo) ?: 'application/octet-stream';
            $declarado = strtolower(trim(explode(';', (string) ($r['mime'] ?: $m->midia_mime))[0]));
            // Office moderno é ZIP: aceito só quando o declarado também é Office.
            if ($detectado === 'application/zip' && str_starts_with($declarado, 'application/vnd.openxmlformats') && str_contains(substr($conteudo, 0, 4096), '[Content_Types].xml')
                && isset(self::TIPOS[$declarado])) {
                $detectado = $declarado;
            }
            if (! isset(self::TIPOS[$detectado])) {
                throw new MidiaRecusada("Tipo de arquivo não permitido ({$detectado}).");
            }
        } catch (MidiaRecusada $e) {
            $m->update(['midia_status' => 'rejeitada', 'ultimo_erro' => mb_substr($e->getMessage(), 0, 300)]);

            return;
        } catch (\Throwable $e) {
            $m->update(['ultimo_erro' => mb_substr($e->getMessage(), 0, 300)]);
            if (! $this->job || $this->job instanceof SyncJob || $this->attempts() >= $this->tries) {
                $m->update(['midia_status' => 'falhou']);
            } else {
                $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
            }

            return;
        }
        $caminho = 'whatsapp/'.$m->conversa_id.'/'.Str::uuid().'.'.self::TIPOS[$detectado];
        Storage::disk('local')->put($caminho, $conteudo);
        $nome = $m->midia_nome ?: 'arquivo-'.$m->id.'.'.self::TIPOS[$detectado];
        $m->update(['midia_status' => 'baixada', 'midia_caminho' => $caminho, 'midia_mime' => $detectado, 'midia_tamanho' => strlen($conteudo),
            'midia_nome' => mb_substr(preg_replace('/[^\pL\pN ._-]/u', '', $nome) ?: 'arquivo', 0, 200), 'ultimo_erro' => null]);
    }

    public function failed(\Throwable $e): void
    {
        WhatsappMensagem::whereKey($this->mensagemId)->where('midia_status', 'pendente')->update(['midia_status' => 'falhou', 'ultimo_erro' => mb_substr($e->getMessage(), 0, 300)]);
    }
}
