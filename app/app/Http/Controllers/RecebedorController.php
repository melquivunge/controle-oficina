<?php

namespace App\Http\Controllers;

use App\Integracoes\Pix\ProvedorPix;
use App\Models\Recebedor;
use App\Models\Unidade;
use App\Services\Auditoria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Conta oficial de recebimento: somente proprietário, com reautenticação (rotas). */
class RecebedorController extends Controller
{
    public function index()
    {
        return view('configuracoes.recebedores', ['unidades' => Unidade::with(['recebedores' => fn ($q) => $q->orderByDesc('id')])->orderBy('nome')->get()]);
    }

    public function store(Request $request, ProvedorPix $pix)
    {
        $dados = $request->validate([
            'unidade_id' => ['required', 'integer', 'exists:unidades,id'],
            'identificador_conta' => ['required', 'string', 'max:120'],
            'descricao' => ['required', 'string', 'max:120'],
        ], [], ['identificador_conta' => 'identificação da conta no provedor', 'descricao' => 'descrição']);
        DB::transaction(function () use ($dados, $request, $pix) {
            $anterior = Recebedor::where('unidade_id', $dados['unidade_id'])->where('ativo', true)->lockForUpdate()->first();
            $anterior?->update(['ativo' => false]);
            $novo = Recebedor::create($dados + ['provedor' => $pix->nome(), 'ativo' => true, 'criado_por' => $request->user()->id]);
            Auditoria::registrar('recebedor_alterado', $novo, $anterior?->only('id', 'identificador_conta', 'provedor'), $novo->only('id', 'identificador_conta', 'provedor'), unidadeId: (int) $dados['unidade_id']);
        });

        return back()->with('sucesso', 'Conta oficial atualizada. Novas cobranças usarão esta conta.');
    }
}
