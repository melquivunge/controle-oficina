<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\Tema;
use App\Models\User;
use App\Services\Auditoria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Temas personalizados: somente proprietário (can:configurar + password.confirm nas rotas). */
class TemaController extends Controller
{
    private const CAMPOS = ['nome', 'base', 'cor_marca', 'cor_menu'];

    public function store(Request $request)
    {
        $tema = new Tema;
        $dados = $this->validar($request);
        DB::transaction(function () use ($dados, $tema) {
            $tema->fill($dados)->save();
            Auditoria::registrar('tema_criado', $tema, null, $tema->only(self::CAMPOS), unidadeId: null);
        });

        return redirect()->to(route('empresa.edit').'#aparencia')->with('sucesso', "Tema \"{$tema->nome}\" criado.");
    }

    public function update(Request $request, Tema $tema)
    {
        $dados = $this->validar($request, $tema);
        DB::transaction(function () use ($dados, $tema) {
            $antes = $tema->only(self::CAMPOS);
            $tema->fill($dados)->save();
            Auditoria::registrar('tema_alterado', $tema, $antes, $tema->only(self::CAMPOS), unidadeId: null);
        });

        return redirect()->to(route('empresa.edit').'#aparencia')->with('sucesso', "Tema \"{$tema->nome}\" atualizado.");
    }

    /** Quem usava o tema (usuários e padrão da empresa) volta ao padrão. */
    public function destroy(Tema $tema)
    {
        DB::transaction(function () use ($tema) {
            User::where('tema', $tema->chave())->update(['tema' => null]);
            Empresa::where('tema_padrao', $tema->chave())->update(['tema_padrao' => null]);
            Auditoria::registrar('tema_excluido', $tema, $tema->only(self::CAMPOS), null, unidadeId: null);
            $tema->delete();
        });

        return redirect()->to(route('empresa.edit').'#aparencia')->with('sucesso', "Tema \"{$tema->nome}\" excluído.");
    }

    private function validar(Request $request, ?Tema $tema = null): array
    {
        $request->merge([
            'nome' => trim((string) $request->input('nome')),
            'cor_marca' => mb_strtolower(trim((string) $request->input('cor_marca'))),
            'cor_menu' => mb_strtolower(trim((string) $request->input('cor_menu'))),
        ]);

        return $request->validateWithBag($tema ? 'tema'.$tema->id : 'tema', [
            'nome' => ['required', 'string', 'min:2', 'max:40', Rule::unique('temas', 'nome')->ignore($tema?->id)],
            'base' => ['required', Rule::in(['claro', 'escuro'])],
            'cor_marca' => ['required', 'regex:/^#[0-9a-f]{6}$/'],
            'cor_menu' => ['required', 'regex:/^#[0-9a-f]{6}$/'],
        ], [
            'cor_marca.regex' => 'Use uma cor no formato #RRGGBB.',
            'cor_menu.regex' => 'Use uma cor no formato #RRGGBB.',
            'nome.unique' => 'Já existe um tema com esse nome.',
        ], ['nome' => 'nome do tema', 'base' => 'base', 'cor_marca' => 'cor da marca', 'cor_menu' => 'cor do menu']);
    }
}
