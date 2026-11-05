<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Proprietário/administrador precisa validar o segundo fator. Não existe autocadastro pela web:
 * a chave é entregue por outro proprietário (tela de usuários) ou pelo console.
 */
class DoisFatores
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user?->exigeDoisFatores() || $request->session()->get('dois_fatores_ok') === $user->id) {
            return $next($request);
        }

        if (! $user->two_factor_confirmed_at) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Verificação em duas etapas não configurada para esta conta. Peça a outro proprietário para gerar sua chave ou use o comando oficina:redefinir-dois-fatores no servidor.']);
        }

        return redirect()->route('dois-fatores.desafio');
    }
}
