<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/** Correlation ID em logs, auditoria e jobs (Context é propagado aos jobs). */
class Correlacao
{
    public function handle(Request $request, Closure $next)
    {
        $id = (string) Str::uuid();
        Context::add('correlacao', $id);
        $response = $next($request);
        $response->headers->set('X-Correlacao', $id);

        return $response;
    }
}
