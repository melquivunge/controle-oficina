<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Integracoes\WhatsApp\MetaCloudApi;
use App\Integracoes\WhatsApp\Provedores;
use App\Services\WhatsApp\Recebimento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/** Webhooks da Central: autentica (assinatura), deduplica e enfileira. Inválido → 401 sem gravar nada. */
class WhatsAppWebhookController extends Controller
{
    /** Verificação da Meta (GET hub.mode/hub.verify_token/hub.challenge). */
    public function verificar(Request $request)
    {
        abort_if(Provedores::paraWebhook('meta') === null, 404);
        $modo = $request->query('hub_mode', $request->query('hub.mode'));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $desafio = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));
        if ($modo === 'subscribe' && MetaCloudApi::tokenVerificacaoValido($token)) {
            return response(mb_substr($desafio, 0, 200), 200, ['Content-Type' => 'text/plain', 'X-Content-Type-Options' => 'nosniff']);
        }

        return response('verificação recusada', 403);
    }

    /** Drope: o número vem na URL; DropeApi confere a assinatura com o segredo dele. */
    public function receberDrope(Request $request, Recebimento $recebimento)
    {
        return $this->receber($request, 'drope', $recebimento);
    }

    public function receber(Request $request, string $provedor, Recebimento $recebimento)
    {
        $adaptador = Provedores::paraWebhook($provedor);
        abort_if($adaptador === null, 404);
        if (! $adaptador->webhookAutentico($request)) {
            if (RateLimiter::attempt('webhook-whatsapp-invalido-log:'.$request->ip(), 5, fn () => true)) {
                Log::warning('webhook whatsapp com assinatura inválida', ['provedor' => $provedor]);
            }

            return response()->json(['erro' => 'assinatura inválida'], 401);
        }
        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return response()->json(['erro' => 'corpo inválido'], 422);
        }
        $novos = $recebimento->registrar($provedor, $adaptador->interpretar($payload));

        return response()->json(['recebido' => true, 'novos' => $novos]);
    }
}
