<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CabecalhosSeguranca
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        // no-referrer: links do cliente carregam token na URL.
        $response->headers->set('Referrer-Policy', 'no-referrer');
        // ponytail: script-src permite inline/eval (Livewire/Alpine). Endurecer com build CSP do Livewire quando avaliado.
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
        }
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
