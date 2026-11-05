<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

// Fora do grupo web: sessão/CSRF dependem do Redis e mascarariam a falha com 500.
// Readiness: dependências críticas (banco e Redis). Integrações externas não entram aqui.
Route::get('/saude', function () {
    $checks = [];
    foreach (['banco' => fn () => DB::select('select 1'), 'redis' => fn () => Redis::ping()] as $nome => $check) {
        try {
            $check();
            $checks[$nome] = 'ok';
        } catch (Throwable $e) {
            report($e);
            $checks[$nome] = 'falha';
        }
    }
    $ok = ! in_array('falha', $checks, true);

    return response()->json(['status' => $ok ? 'ok' : 'falha', 'checks' => $checks], $ok ? 200 : 503);
});
