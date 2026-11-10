<?php

namespace App\Integracoes\WhatsApp;

use App\Models\WhatsappNumero;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Drope (dropestore.com): provedor NÃO OFICIAL por QR Code. Risco de banimento do número aceito pelo usuário (10/11/2026).
 * Envio e assinatura do webhook seguem a documentação pública. O corpo dos eventos (messages, messages_update) NÃO é
 * documentado: o mapeamento abaixo é hipótese baseada na resposta do envio (messageid, chatid, fromMe, messageType…)
 * e precisa ser conferido com um webhook real. Quando nada é reconhecido, só a estrutura (chaves, sem valores) vai ao log.
 * Webhook por número: /webhooks/whatsapp/drope/{numero}, HMAC com o signing_secret desse número.
 */
class DropeApi implements ProvedorWhatsApp
{
    private const BASE = 'https://dropestore.com/wp-json/wdm/v1';

    private ?WhatsappNumero $numeroWebhook = null;

    public function nome(): string
    {
        return 'drope';
    }

    /** X-DROPE-Signature = sha256=HMAC(corpo cru, signing_secret do número da URL). */
    public function webhookAutentico(Request $request): bool
    {
        $numero = WhatsappNumero::where('provedor', 'drope')->where('ativo', true)->find((int) $request->route('numero'));
        $segredo = $numero?->credencial('signing_secret');
        $assinatura = (string) $request->header('X-DROPE-Signature');
        if ($segredo === null || ! hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $segredo), $assinatura)) {
            return false;
        }
        $this->numeroWebhook = $numero;

        return true;
    }

    public function interpretar(array $payload): array
    {
        if (! $this->numeroWebhook) {
            return [];
        }
        $tipoEvento = strtolower((string) ($payload['EventType'] ?? $payload['event_type'] ?? request()->header('X-DROPE-Event', '')));
        $eventos = match ($tipoEvento) {
            'messages' => $this->mensagens($payload),
            'messages_update' => $this->status($payload),
            default => [],
        };
        if ($eventos === [] && in_array($tipoEvento, ['messages', 'messages_update'], true)) {
            Log::info('webhook drope não reconhecido (só estrutura, sem valores)', ['evento' => $tipoEvento, 'estrutura' => self::estrutura($payload)]);
        }

        return $eventos;
    }

    /** Chaves e tipos, sem valores: permite mapear o formato real sem gravar conteúdo de cliente. */
    public static function estrutura(array $a, int $nivel = 0): array
    {
        $r = [];
        foreach (array_slice($a, 0, 40, true) as $k => $v) {
            $k = is_string($k) && preg_match('/^[A-Za-z_]{1,30}$/', $k) ? $k : '*'; // chave que pareça dado (telefone, id) não vai ao log
            $r[$k] = is_array($v) ? ($nivel < 3 ? self::estrutura($v, $nivel + 1) : 'array') : get_debug_type($v);
        }

        return $r;
    }

    private function mensagens(array $payload): array
    {
        $lista = isset($payload['messages']) && is_array($payload['messages']) ? $payload['messages'] : [$payload['message'] ?? $payload['data'] ?? $payload];
        $eventos = [];
        foreach ($lista as $m) {
            if (! is_array($m) || ($m['isGroup'] ?? false) || ($m['wasSentByApi'] ?? false)) {
                continue; // grupos fora do escopo; o que a API enviou já está na Central
            }
            $id = (string) ($m['messageid'] ?? $m['id'] ?? '');
            $id = str_contains($id, ':') ? substr($id, strrpos($id, ':') + 1) : $id; // "dono:ID" → ID, igual ao data.messageid do envio
            $deMim = (bool) ($m['fromMe'] ?? false);
            $contato = self::digitos((string) ($m['chatid'] ?? ($deMim ? '' : ($m['sender_pn'] ?? $m['sender'] ?? ''))));
            if ($id === '' || strlen($id) > 150 || strlen($contato) < 10 || strlen($contato) > 15) {
                continue;
            }
            $ts = (int) ($m['messageTimestamp'] ?? time());
            $base = ['id' => $id, 'contato' => $contato, 'nome' => $deMim ? null : (isset($m['senderName']) ? mb_substr((string) $m['senderName'], 0, 120) : null),
                'direcao' => $deMim ? 'saida' : 'entrada', 'timestamp' => $ts > 9999999999 ? intdiv($ts, 1000) : $ts];
            $eventos[] = ['evento_id' => ($deMim ? 'eco:' : 'msg:').$id, 'tipo' => $deMim ? 'eco' : 'mensagem', 'numero_id' => $this->numeroWebhook->id,
                'dados' => $base + $this->conteudo($m, $id)];
        }

        return $eventos;
    }

    private function conteudo(array $m, string $id): array
    {
        $tipo = strtolower((string) ($m['messageType'] ?? ''));
        $texto = isset($m['text']) && is_string($m['text']) ? $m['text'] : null;
        $c = is_array($m['content'] ?? null) ? $m['content'] : [];
        $midia = fn (string $t) => ['tipo' => $t, 'texto' => $texto !== null && $texto !== '' ? mb_substr($texto, 0, 1024) : null,
            'midia' => ['id' => $id, 'mime' => mb_substr((string) ($c['mimetype'] ?? $m['mimetype'] ?? ''), 0, 100), 'nome' => isset($c['fileName']) ? mb_substr((string) $c['fileName'], 0, 200) : null]];

        return match (true) {
            str_contains($tipo, 'image') => $midia('imagem'),
            str_contains($tipo, 'document') => $midia('documento'),
            str_contains($tipo, 'audio') || str_contains($tipo, 'ptt') => $midia('audio'),
            str_contains($tipo, 'location') => ['tipo' => 'localizacao', 'localizacao' => ['lat' => (float) ($c['degreesLatitude'] ?? 0), 'lng' => (float) ($c['degreesLongitude'] ?? 0), 'nome' => $c['name'] ?? null]],
            $tipo === '' || str_contains($tipo, 'conversation') || str_contains($tipo, 'text') => ['tipo' => 'texto', 'texto' => mb_substr((string) $texto, 0, 4096)],
            default => ['tipo' => 'texto', 'texto' => '[Mensagem do tipo "'.mb_substr($tipo, 0, 30).'" não suportada pela Central — veja no aparelho]'],
        };
    }

    private function status(array $payload): array
    {
        $e = (array) ($payload['event'] ?? $payload['data'] ?? $payload);
        $status = ['sent' => 'enviada', 'serverack' => 'enviada', 'delivered' => 'entregue', 'deliveryack' => 'entregue', 'read' => 'lida', 'played' => 'lida', 'failed' => 'falhou', 'error' => 'falhou'][strtolower((string) ($e['Type'] ?? $e['type'] ?? $e['status'] ?? ''))] ?? null;
        $ids = (array) ($e['MessageIDs'] ?? $e['messageIds'] ?? (isset($e['messageid']) ? [$e['messageid']] : []));
        $eventos = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            if ($status && $id !== '' && strlen($id) <= 150) {
                $eventos[] = ['evento_id' => "st:{$id}:{$status}", 'tipo' => 'status', 'numero_id' => $this->numeroWebhook->id,
                    'dados' => ['id' => $id, 'status' => $status, 'timestamp' => time(), 'erro' => null]];
            }
        }

        return $eventos;
    }

    private static function digitos(string $jid): string
    {
        return preg_replace('/\D/', '', explode('@', $jid)[0]);
    }

    private function http(WhatsappNumero $n, int $timeout = 20)
    {
        $token = $n->credencial('token') ?? throw new RuntimeException('Token do dispositivo Drope ausente: cadastre em Configurações › Números de WhatsApp.');

        return Http::withHeaders(['token' => $token])->acceptJson()->timeout($timeout)->withoutRedirecting();
    }

    private function exigirSucesso(Response $r, string $operacao): void
    {
        if ($r->successful() && $r->json('success') !== false) {
            return;
        }
        $codigo = (string) $r->json('code');
        $motivo = match ($codigo) {
            'DEVICE_DISCONNECTED' => 'aparelho desconectado no Drope: leia o QR Code de novo',
            'PLAN_INACTIVE', 'TRIAL_EXPIRED' => 'plano do Drope inativo ou vencido',
            'DEVICE_SUSPENDED' => 'aparelho suspenso no Drope',
            'RATE_LIMITED' => 'limite de envio do Drope atingido, nova tentativa em instantes',
            default => mb_substr((string) ($r->json('error') ?? 'HTTP '.$r->status()), 0, 200),
        };
        throw new RuntimeException("Drope recusou o {$operacao}: {$motivo}".($codigo !== '' ? " ({$codigo})" : '').'.');
    }

    private function enviar(WhatsappNumero $n, string $rota, array $corpo, int $timeout = 20): string
    {
        $r = $this->http($n, $timeout)->post(self::BASE.$rota, $corpo);
        $this->exigirSucesso($r, 'envio');
        $id = $r->json('data.messageid');

        return is_string($id) && $id !== '' ? $id : throw new RuntimeException('Drope não retornou o id da mensagem.');
    }

    public function enviarTexto(WhatsappNumero $numero, string $para, string $texto): string
    {
        return $this->enviar($numero, '/send/text', ['number' => self::digitos($para), 'text' => $texto]);
    }

    public function enviarModelo(WhatsappNumero $numero, string $para, string $modelo, string $idioma): string
    {
        throw new RuntimeException('O Drope não usa modelos aprovados: envie texto livre.');
    }

    /** Arquivo vai em base64: a OS nunca fica em URL pública. */
    public function enviarMidia(WhatsappNumero $numero, string $para, string $conteudo, string $mime, string $nomeArquivo, ?string $legenda): string
    {
        $imagem = str_starts_with($mime, 'image/');

        return $this->enviar($numero, '/send/media', ['number' => self::digitos($para), 'type' => $imagem ? 'image' : 'document',
            'file' => base64_encode($conteudo), 'mimetype' => $mime] + ($imagem ? [] : ['docName' => $nomeArquivo]) + ($legenda ? ['text' => $legenda] : []), 60);
    }

    /** POST /message/download pedindo base64 (nunca segue URL devolvida: evita SSRF). Formato da resposta não documentado: hipótese. */
    public function baixarMidia(WhatsappNumero $numero, string $midiaId, int $limiteBytes): array
    {
        $maximo = intdiv($limiteBytes * 4, 3) + 8192; // base64 + JSON; aborta a transferência antes de estourar a memória
        $r = $this->http($numero, 60)->withOptions(['progress' => function ($total, $baixado) use ($maximo) {
            if ($total > $maximo || $baixado > $maximo) {
                throw new MidiaRecusada('Arquivo maior que o limite permitido.');
            }
        }])->post(self::BASE.'/message/download', ['id' => $midiaId, 'return_base64' => true, 'return_link' => false]);
        $this->exigirSucesso($r, 'download de mídia');
        $b64 = $r->json('base64Data') ?? $r->json('data.base64Data') ?? $r->json('base64') ?? $r->json('data.base64');
        if (! is_string($b64) || $b64 === '') {
            Log::info('download drope sem base64 reconhecido', ['estrutura' => self::estrutura((array) $r->json())]);
            throw new RuntimeException('Drope não devolveu o arquivo da mídia.');
        }
        $b64 = preg_replace('/^data:[^,]*,/', '', $b64);
        if (intdiv(strlen($b64), 4) * 3 > $limiteBytes + 3) {
            throw new MidiaRecusada('Arquivo maior que o limite permitido.');
        }
        $conteudo = base64_decode($b64, true);
        if ($conteudo === false) {
            throw new RuntimeException('Drope devolveu mídia ilegível.');
        }
        if (strlen($conteudo) > $limiteBytes) {
            throw new MidiaRecusada('Arquivo maior que o limite permitido.');
        }

        return ['conteudo' => $conteudo, 'mime' => $r->json('mimetype') ?? $r->json('data.mimetype')];
    }

    /** Registra o webhook deste número no Drope e devolve o signing_secret (exibido só na criação). */
    public function registrarWebhook(WhatsappNumero $numero, string $url): string
    {
        $r = $this->http($numero)->post(self::BASE.'/webhook', ['enabled' => true, 'url' => $url,
            'events' => ['messages', 'messages_update', 'connection'], 'excludeMessages' => ['wasSentByApi', 'isGroupYes']]);
        $this->exigirSucesso($r, 'registro do webhook');
        foreach (['signing_secret', 'data.signing_secret', 'secret', 'data.secret', '0.signing_secret'] as $chave) {
            $s = $r->json($chave);
            if (is_string($s) && $s !== '') {
                return $s;
            }
        }
        Log::info('registro webhook drope sem segredo reconhecido', ['estrutura' => self::estrutura((array) $r->json())]);
        throw new RuntimeException('Drope registrou o webhook mas não devolveu o segredo de assinatura. Copie-o do painel do Drope para o campo "Segredo do webhook".');
    }
}
