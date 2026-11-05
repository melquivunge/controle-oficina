<?php

namespace App\Http\Controllers;

use App\Services\Auditoria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use App\Support\Aparencia;
use App\Support\Normaliza;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** Perfil do próprio usuário: nome, telefone, cargo e foto; e-mail, papel e unidade continuam com o proprietário. */
class ContaController extends Controller
{
    public function show(Request $request)
    {
        return view('conta.show', ['usuario' => $request->user()->load('unidade')]);
    }

    public function perfil(Request $request)
    {
        $request->merge(['telefone' => Normaliza::telefone($request->input('telefone')) ?: null]);
        $dados = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'telefone' => ['nullable', 'regex:/^\d{10,11}$/'],
            'cargo' => ['nullable', 'string', 'max:80'],
            'foto' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000'],
            'remover_foto' => ['nullable', 'boolean'],
            'receber_avisos_whatsapp' => ['nullable', 'boolean'],
        ], ['telefone.regex' => 'Informe o telefone com DDD (10 ou 11 dígitos).', 'foto.max' => 'A foto deve ter até 2 MB.'], ['name' => 'nome', 'foto' => 'foto']);
        $user = $request->user();
        // Aviso de serviço pelo WhatsApp (opcional): só faz sentido com telefone no perfil.
        $avisos = $request->boolean('receber_avisos_whatsapp');
        if ($avisos && ! $dados['telefone']) {
            throw ValidationException::withMessages(['receber_avisos_whatsapp' => 'Informe seu telefone com DDD para receber avisos pelo WhatsApp.']);
        }
        $antes = $user->only('name', 'telefone', 'cargo', 'receber_avisos_whatsapp') + ['foto' => (bool) $user->foto_caminho];
        $anterior = $user->foto_caminho;
        if ($request->hasFile('foto')) {
            $user->foto_caminho = $request->file('foto')->store('fotos-usuarios', 'local');
        } elseif ($request->boolean('remover_foto')) {
            $user->foto_caminho = null;
        }
        $user->forceFill(['name' => $dados['name'], 'telefone' => $dados['telefone'], 'cargo' => $dados['cargo'] ?? null, 'receber_avisos_whatsapp' => $avisos])->save();
        if ($anterior && $anterior !== $user->foto_caminho) {
            Storage::disk('local')->delete($anterior);
        }
        Auditoria::registrar('perfil_alterado', $user, $antes, $user->only('name', 'telefone', 'cargo', 'receber_avisos_whatsapp') + ['foto' => (bool) $user->foto_caminho], ator: $user);

        return redirect()->route('conta')->with('sucesso', 'Perfil atualizado.');
    }

    /** Tema do próprio usuário; vazio segue o padrão da empresa. */
    public function tema(Request $request)
    {
        $dados = $request->validate(['tema' => ['nullable', Rule::in(array_keys(Aparencia::opcoes()))]], [], ['tema' => 'tema']);
        $user = $request->user();
        $antes = $user->only('tema');
        $user->forceFill(['tema' => $dados['tema'] ?? null])->save();
        Auditoria::registrar('tema_usuario_alterado', $user, $antes, $user->only('tema'), ator: $user);

        return redirect()->to(route('conta').'#aparencia')->with('sucesso', 'Tema atualizado.');
    }

    /** Foto de qualquer usuário ativo da empresa para quem está autenticado (aparece em menus e listas). */
    public function foto(User $usuario)
    {
        abort_unless($usuario->foto_caminho && Storage::disk('local')->exists($usuario->foto_caminho), 404);

        return Storage::disk('local')->response($usuario->foto_caminho, 'foto', [
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=86400', 'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    public function senha(Request $request)
    {
        $dados = $request->validate([
            'senha_atual' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ], [], ['senha_atual' => 'senha atual', 'password' => 'nova senha']);
        $user = $request->user();
        if (! Hash::check($dados['senha_atual'], $user->password)) {
            throw ValidationException::withMessages(['senha_atual' => 'Senha atual incorreta.']);
        }
        $user->password = $dados['password'];
        $user->save();
        Auth::logoutOtherDevices($dados['password']);
        Auditoria::registrar('senha_alterada', $user, depois: ['senha_redefinida' => true], ator: $user);

        return redirect()->route('conta')->with('sucesso', 'Senha alterada. Outras sessões abertas foram encerradas.');
    }
}
