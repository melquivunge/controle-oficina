<?php

use App\Http\Controllers\Webhooks\PixWebhookController;
use Illuminate\Support\Facades\Route;

// Fora do grupo web: sem sessão/CSRF; autenticação pela assinatura do provedor.
Route::post('/webhooks/pix/{provedor}', PixWebhookController::class)->middleware('throttle:120,1')->name('webhooks.pix');

// Central de WhatsApp (RF23): simulador (HMAC próprio, só em simulação) e Meta Cloud API (verificação GET + X-Hub-Signature-256; não homologado).
Route::get('/webhooks/whatsapp/meta', [\App\Http\Controllers\Webhooks\WhatsAppWebhookController::class, 'verificar'])->middleware('throttle:60,1')->name('webhooks.whatsapp.verificar');
Route::post('/webhooks/whatsapp/{provedor}', [\App\Http\Controllers\Webhooks\WhatsAppWebhookController::class, 'receber'])
    ->whereIn('provedor', ['simulado', 'meta'])->middleware('throttle:240,1')->name('webhooks.whatsapp');
// Drope (não oficial, QR Code): um webhook por número; X-DROPE-Signature com o signing_secret desse número.
Route::post('/webhooks/whatsapp/drope/{numero}', [\App\Http\Controllers\Webhooks\WhatsAppWebhookController::class, 'receberDrope'])
    ->whereNumber('numero')->middleware('throttle:240,1')->name('webhooks.whatsapp.drope');
