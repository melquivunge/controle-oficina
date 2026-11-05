<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Desativação revoga o acesso na próxima requisição, inclusive de sessões já abertas. */
class ContaAtiva
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && (! $user->ativo || $user->unidadeInativa())) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Esta conta ou unidade está desativada. Procure o proprietário.']);
        }

        return $next($request);
    }
}
