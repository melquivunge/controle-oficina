<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Integracoes\Pix\ProvedorPix;
use App\Jobs\ProcessarEventoPix;
use App\Models\IntegrationEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Recebe, autentica e deduplica; o processamento ocorre no worker após o commit. */
class PixWebhookController extends Controller
{
    public function __invoke(Request $request, string $provedor, ProvedorPix $pix)
    {
        abort_unless($provedor === $pix->nome(), 404);
        if (! $pix->webhookAutentico($request)) {
            if (\Illuminate\Support\Facades\RateLimiter::attempt('webhook-invalido-log:'.$request->ip(), 5, fn () => true)) {
                Log::warning('webhook pix com assinatura inválida', ['provedor' => $provedor]); // no máximo 5/min por IP
            }

            return response()->json(['erro' => 'assinatura inválida'], 401);
        }
        $dados = $request->validate(['evento_id' => ['required', 'string', 'max:120'], 'tipo' => ['required', 'string', 'max:60'], 'txid' => ['required', 'string', 'max:64']]);

        DB::transaction(function () use ($provedor, $dados) {
            $novo = IntegrationEvent::query()->insertOrIgnore([
                'provedor' => $provedor, 'evento_id' => $dados['evento_id'], 'tipo' => $dados['tipo'],
                'payload' => json_encode(['txid' => $dados['txid']]), 'recebido_em' => now(),
            ]);
            if ($novo) {
                $id = IntegrationEvent::where('provedor', $provedor)->where('evento_id', $dados['evento_id'])->value('id');
                ProcessarEventoPix::dispatch($id)->afterCommit();
            }
        });

        return response()->json(['recebido' => true]);
    }
}
