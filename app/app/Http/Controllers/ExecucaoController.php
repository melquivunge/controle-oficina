<?php

namespace App\Http\Controllers;

use App\Models\Ordem;
use App\Services\Execucao;
use Illuminate\Http\Request;

class ExecucaoController extends Controller
{
    public function __construct(private Execucao $execucao) {}

    public function iniciar(Request $request, Ordem $ordem)
    {
        $this->execucao->iniciar($ordem, $request->user());

        return back()->with('sucesso', 'Execução iniciada. Somente os serviços aprovados estão liberados.');
    }

    public function concluirItem(Request $request, Ordem $ordem)
    {
        $this->execucao->concluirItem($ordem, $request->integer('item'), $request->user());

        return back()->with('sucesso', 'Serviço marcado como concluído.');
    }

    public function pronto(Request $request, Ordem $ordem)
    {
        $dados = $request->validate(['resumo' => ['nullable', 'string', 'max:1000']]);
        $this->execucao->marcarPronto($ordem, $request->user(), $dados['resumo'] ?? null);

        return back()->with('sucesso', 'Veículo pronto para entrega.');
    }
}
