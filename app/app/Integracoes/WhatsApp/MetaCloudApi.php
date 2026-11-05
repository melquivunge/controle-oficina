<?php

namespace App\Integracoes\WhatsApp;

use App\Models\WhatsappNumero;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Adaptador da WhatsApp Cloud API (Meta). NÃO HOMOLOGADO COM CONTA REAL: escrito pela documentação pública e
 * verificado apenas com Http::fake nos testes. Inclui coexistência (ecos do app WhatsApp Business: smb_message_echoes)
 * e importação de histórico (history). Credenciais por número, cifradas; nunca registradas em log.
 */
class MetaCloudApi implements ProvedorWhatsApp
{
    private const TIPOS = ['text' => 'texto', 'image' => 'imagem', 'audio' => 'audio', 'document' => 'documento', 'location' => 'localizacao'];

    public function nome(): string
    {
        return 'meta';
    }

    private function base(): string
    {
        return 'https://graph.facebook.com/'.config('oficina.whatsapp.meta_versao', 'v21.0');
    }

    /** GET de verificação: hub.verify_token deve coincidir com o de algum número Meta ativo. */
    public static function tokenVerificacaoValido(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        foreach (WhatsappNumero::where('provedor', 'meta')->where('ativo', true)->get() as $n) {
            $esperado = $n->credencial('verify_token');
            if ($esperado !== null && hash_equals($esperado, $token)) {
                return true;
            }
        }

        return false;
    }

    /** X-Hub-Signature-256 = sha256=HMAC(corpo bruto, app secret). Todo número citado no corpo precisa validar a assinatura. */
    public function webhookAutentico(Request $request): bool
    {
        $assinatura = (string) $request->header('X-Hub-Signature-256');
        $corpo = $request->getContent();
        $payload = json_decode($corpo, true);
        if (! str_starts_with($assinatura, 'sha256=') || ! is_array($payload)) {
            return false;
        }
        $ids = $this->idsDeNumero($payload);
        if ($ids === []) {
            return false;
        }
        $numeros = WhatsappNumero::where('provedor', 'meta')->where('ativo', true)->whereIn('phone_number_id', $ids)->get();
        if ($numeros->count() !== count($ids)) {
            return false;
        }
        foreach ($numeros as $n) {
            $segredo = $n->credencial('app_secret');
            if ($segredo === null || ! hash_equals('sha256='.hash_hmac('sha256', $corpo, $segredo), $assinatura)) {
                return false;
            }
        }

        return true;
    }

    private function idsDeNumero(array $payload): array
    {
        $ids = [];
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $id = $change['value']['metadata']['phone_number_id'] ?? null;
                if (is_string($id) && $id !== '') {
                    $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    public function interpretar(array $payload): array
    {
        $eventos = [];
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $v = (array) ($change['value'] ?? []);
                $numero = WhatsappNumero::where('provedor', 'meta')->where('phone_number_id', (string) ($v['metadata']['phone_number_id'] ?? ''))->first();
                if (! $numero) {
                    continue;
                }
                $nomes = collect((array) ($v['contacts'] ?? []))->mapWithKeys(fn ($c) => [(string) ($c['wa_id'] ?? '') => $c['profile']['name'] ?? null]);
                foreach ((array) ($v['messages'] ?? []) as $m) {
                    if ($d = $this->mensagem((array) $m, (string) ($m['from'] ?? ''), 'entrada')) {
                        $eventos[] = ['evento_id' => 'msg:'.$d['id'], 'tipo' => 'mensagem', 'numero_id' => $numero->id, 'dados' => $d + ['nome' => $nomes[$d['contato']] ?? null]];
                    }
                }
                // Coexistência: mensagens enviadas pelo app WhatsApp Business do celular da empresa.
                foreach ((array) ($v['message_echoes'] ?? []) as $m) {
                    if ($d = $this->mensagem((array) $m, (string) ($m['to'] ?? ''), 'saida')) {
                        $eventos[] = ['evento_id' => 'eco:'.$d['id'], 'tipo' => 'eco', 'numero_id' => $numero->id, 'dados' => $d + ['nome' => null]];
                    }
                }
                // Coexistência: importação do histórico do app (horário original; não gera alerta).
                $empresa = preg_replace('/\D/', '', (string) ($v['metadata']['display_phone_number'] ?? ''));
                foreach ((array) ($v['history'] ?? []) as $lote) {
                    foreach ((array) ($lote['threads'] ?? []) as $thread) {
                        $contato = (string) ($thread['id'] ?? '');
                        foreach ((array) ($thread['messages'] ?? []) as $m) {
                            $deEmpresa = preg_replace('/\D/', '', (string) ($m['from'] ?? '')) === $empresa && $empresa !== '';
                            if ($d = $this->mensagem((array) $m, $contato, $deEmpresa ? 'saida' : 'entrada')) {
                                $eventos[] = ['evento_id' => 'hist:'.$d['id'], 'tipo' => 'historico', 'numero_id' => $numero->id, 'dados' => $d + ['nome' => $nomes[$d['contato']] ?? null]];
                            }
                        }
                    }
                }
                foreach ((array) ($v['statuses'] ?? []) as $s) {
                    $status = ['sent' => 'enviada', 'delivered' => 'entregue', 'read' => 'lida', 'failed' => 'falhou'][$s['status'] ?? ''] ?? null;
                    $id = (string) ($s['id'] ?? '');
                    if ($status && $id !== '' && strlen($id) <= 150) {
                        $erro = $s['errors'][0] ?? null;
                        $eventos[] = ['evento_id' => "st:{$id}:{$status}", 'tipo' => 'status', 'numero_id' => $numero->id, 'dados' => ['id' => $id, 'status' => $status,
                            'timestamp' => (int) ($s['timestamp'] ?? time()), 'erro' => $erro ? mb_substr(trim(($erro['code'] ?? '').' '.($erro['title'] ?? '').' '.($erro['error_data']['details'] ?? '')), 0, 300) : null]];
                    }
                }
            }
        }

        return $eventos;
    }

    /** Normaliza uma mensagem da Cloud API. Tipos fora do escopo (vídeo, figurinha, reação…) entram como texto informativo. */
    private function mensagem(array $m, string $contato, string $direcao): ?array
    {
        $id = (string) ($m['id'] ?? '');
        $contato = preg_replace('/\D/', '', $contato);
        if ($id === '' || strlen($id) > 150 || strlen($contato) < 10 || strlen($contato) > 15) {
            return null;
        }
        $base = ['id' => $id, 'contato' => $contato, 'direcao' => $direcao, 'timestamp' => (int) ($m['timestamp'] ?? time())];
        $tipoMeta = (string) ($m['type'] ?? '');
        $tipo = self::TIPOS[$tipoMeta] ?? null;

        return $base + match ($tipo) {
            'texto' => ['tipo' => 'texto', 'texto' => mb_substr((string) ($m['text']['body'] ?? ''), 0, 4096)],
            'imagem', 'audio', 'documento' => ['tipo' => $tipo, 'texto' => isset($m[$tipoMeta]['caption']) ? mb_substr((string) $m[$tipoMeta]['caption'], 0, 1024) : null,
                'midia' => ['id' => (string) ($m[$tipoMeta]['id'] ?? ''), 'mime' => (string) ($m[$tipoMeta]['mime_type'] ?? ''), 'nome' => $m[$tipoMeta]['filename'] ?? null]],
            'localizacao' => ['tipo' => 'localizacao', 'localizacao' => ['lat' => (float) ($m['location']['latitude'] ?? 0), 'lng' => (float) ($m['location']['longitude'] ?? 0), 'nome' => $m['location']['name'] ?? null]],
            default => ['tipo' => 'texto', 'texto' => '[Mensagem do tipo "'.mb_substr($tipoMeta ?: 'desconhecido', 0, 30).'" não suportada pela Central — veja no aparelho]'],
        };
    }

    private function token(WhatsappNumero $n): string
    {
        return $n->credencial('token') ?? throw new RuntimeException('Credenciais da API oficial ausentes para este número: cadastre o token de acesso.');
    }

    private function enviar(WhatsappNumero $n, array $corpo): string
    {
        $r = Http::withToken($this->token($n))->acceptJson()->timeout(20)
            ->post($this->base().'/'.$n->phone_number_id.'/messages', ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual'] + $corpo);
        $this->exigirSucesso($r, 'envio');
        $id = $r->json('messages.0.id');

        return is_string($id) && $id !== '' ? $id : throw new RuntimeException('API oficial não retornou o id da mensagem.');
    }

    private function exigirSucesso(Response $r, string $operacao): void
    {
        if (! $r->successful()) {
            $erro = $r->json('error.message') ?? 'HTTP '.$r->status();
            throw new RuntimeException("API oficial recusou o {$operacao}: ".mb_substr((string) $erro, 0, 200));
        }
    }

    public function enviarTexto(WhatsappNumero $numero, string $para, string $texto): string
    {
        return $this->enviar($numero, ['to' => $para, 'type' => 'text', 'text' => ['body' => $texto, 'preview_url' => false]]);
    }

    public function enviarModelo(WhatsappNumero $numero, string $para, string $modelo, string $idioma): string
    {
        return $this->enviar($numero, ['to' => $para, 'type' => 'template', 'template' => ['name' => $modelo, 'language' => ['code' => $idioma]]]);
    }

    public function enviarMidia(WhatsappNumero $numero, string $para, string $conteudo, string $mime, string $nomeArquivo, ?string $legenda): string
    {
        $up = Http::withToken($this->token($numero))->acceptJson()->timeout(60)
            ->attach('file', $conteudo, $nomeArquivo, ['Content-Type' => $mime])
            ->post($this->base().'/'.$numero->phone_number_id.'/media', ['messaging_product' => 'whatsapp', 'type' => $mime]);
        $this->exigirSucesso($up, 'upload de mídia');
        $midiaId = (string) $up->json('id');
        $tipo = str_starts_with($mime, 'image/') ? 'image' : 'document';
        $objeto = ['id' => $midiaId] + ($legenda ? ['caption' => $legenda] : []) + ($tipo === 'document' ? ['filename' => $nomeArquivo] : []);

        return $this->enviar($numero, ['to' => $para, 'type' => $tipo, $tipo => $objeto]);
    }

    /** GET /{media_id} → url temporária → download autenticado. Tamanho conferido antes e depois do download. */
    public function baixarMidia(WhatsappNumero $numero, string $midiaId, int $limiteBytes): array
    {
        $token = $this->token($numero);
        $meta = Http::withToken($token)->acceptJson()->timeout(20)->withoutRedirecting()->get($this->base().'/'.rawurlencode($midiaId));
        $this->exigirSucesso($meta, 'consulta de mídia');
        $url = (string) $meta->json('url');
        // O token vai junto no download: só para hosts da Meta (evita vazar credencial/SSRF por URL manipulada).
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (! str_starts_with($url, 'https://') || ! preg_match('/(^|\.)(fbsbx\.com|facebook\.com|whatsapp\.net)$/', $host)) {
            throw new RuntimeException('API oficial retornou endereço de mídia inválido.');
        }
        if ((int) $meta->json('file_size', 0) > $limiteBytes) {
            throw new MidiaRecusada('Arquivo maior que o limite permitido.');
        }
        $arquivo = Http::withToken($token)->timeout(60)->withoutRedirecting()->get($url);
        if ((int) $arquivo->header('Content-Length') > $limiteBytes) {
            throw new MidiaRecusada('Arquivo maior que o limite permitido.');
        }
        $this->exigirSucesso($arquivo, 'download de mídia');
        $conteudo = $arquivo->body();
        if (strlen($conteudo) > $limiteBytes) {
            throw new MidiaRecusada('Arquivo maior que o limite permitido.');
        }

        return ['conteudo' => $conteudo, 'mime' => $meta->json('mime_type')];
    }
}
