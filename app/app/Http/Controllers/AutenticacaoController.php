<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Auditoria;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AutenticacaoController extends Controller
{
    public function formulario()
    {
        return view('auth.entrar');
    }

    public function entrar(Request $request)
    {
        $dados = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ], [], ['email' => 'e-mail', 'password' => 'senha']);

        $user = User::where('email', mb_strtolower($dados['email']))->first();
        // Hash sempre verificado: tempo de resposta não revela se o e-mail existe.
        $senhaOk = Hash::check($dados['password'], $user?->password ?? Cache::rememberForever('hash-ficticio-login', fn () => Hash::make(Str::random(40))));
        if (! $user || ! $senhaOk || ! $user->ativo) {
            if ($user) {
                Auditoria::registrar('login_recusado', $user, motivo: $user->ativo ? 'senha incorreta' : 'conta desativada', ator: $user);
            }
            throw ValidationException::withMessages(['email' => 'E-mail ou senha incorretos, ou conta desativada.']);
        }

        Auth::login($user);
        $request->session()->regenerate();
        Auditoria::registrar('login', $user, ator: $user);

        return redirect()->intended(route('painel'));
    }

    public function sair(Request $request)
    {
        if ($user = $request->user()) {
            Auditoria::registrar('logout', $user, ator: $user);
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function desafio(Request $request)
    {
        abort_unless($request->user()->two_factor_confirmed_at, 404);

        return view('auth.dois-fatores-desafio');
    }

    public function validarDesafio(Request $request)
    {
        $user = $request->user();
        abort_unless($user->two_factor_confirmed_at, 404);
        $request->validate(['codigo' => ['required', 'string', 'max:10']], [], ['codigo' => 'código']);
        $passo = Totp::passo($user->two_factor_secret, $request->input('codigo'));
        // Cada código vale uma única vez (Cache::add é atômico no Redis).
        if ($passo === null || ! Cache::add("totp-usado:{$user->id}:{$passo}", true, 120)) {
            Auditoria::registrar('dois_fatores_recusado', $user);
            throw ValidationException::withMessages(['codigo' => 'Código inválido ou já utilizado. Aguarde o próximo código.']);
        }
        $request->session()->regenerate();
        $request->session()->put('dois_fatores_ok', $user->id);

        return redirect()->intended(route('painel'));
    }

    public function formularioConfirmarSenha()
    {
        return view('auth.confirmar-senha');
    }

    public function confirmarSenha(Request $request)
    {
        $request->validate(['password' => ['required', 'string']], [], ['password' => 'senha']);
        if (! Hash::check($request->input('password'), $request->user()->password)) {
            Auditoria::registrar('reautenticacao_recusada', $request->user());
            throw ValidationException::withMessages(['password' => 'Senha incorreta.']);
        }
        $request->session()->passwordConfirmed();

        return redirect()->intended(route('painel'));
    }
}
