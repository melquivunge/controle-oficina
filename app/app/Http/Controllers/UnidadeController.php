<?php

namespace App\Http\Controllers;

use App\Models\Unidade;
use App\Services\Auditoria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UnidadeController extends Controller
{
    public function index()
    {
        return view('configuracoes.unidades', ['unidades' => Unidade::withCount('usuarios')->orderBy('nome')->get()]);
    }

    public function store(Request $request)
    {
        $dados = $this->validar($request);
        $unidade = DB::transaction(function () use ($dados) {
            $unidade = Unidade::create($dados);
            Auditoria::registrar('unidade_criada', $unidade, depois: $unidade->only('codigo', 'nome', 'ativa'), unidadeId: $unidade->id);

            return $unidade;
        });

        return redirect()->route('unidades.index')->with('sucesso', "Unidade {$unidade->nome} criada.");
    }

    public function update(Request $request, Unidade $unidade)
    {
        $dados = $this->validar($request, $unidade);
        DB::transaction(function () use ($unidade, $dados) {
            $antes = $unidade->only('codigo', 'nome', 'ativa');
            $unidade->update($dados);
            Auditoria::registrar('unidade_alterada', $unidade, $antes, $unidade->only('codigo', 'nome', 'ativa'), unidadeId: $unidade->id);
        });

        return redirect()->route('unidades.index')->with('sucesso', "Unidade {$unidade->nome} atualizada.");
    }

    private function validar(Request $request, ?Unidade $unidade = null): array
    {
        $request->merge(['codigo' => mb_strtoupper(trim((string) $request->input('codigo')))]);

        return $request->validate([
            'codigo' => ['required', 'alpha_num:ascii', 'max:10', Rule::unique('unidades')->ignore($unidade)],
            'nome' => ['required', 'string', 'max:120'],
            'ativa' => ['boolean'],
        ], [], ['codigo' => 'código']) + ['ativa' => $request->boolean('ativa', $unidade === null)];
    }
}
