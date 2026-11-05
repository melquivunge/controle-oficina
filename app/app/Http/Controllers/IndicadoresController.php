<?php

namespace App\Http\Controllers;

use App\Models\Unidade;
use App\Services\Indicadores;
use App\Support\Fuso;
use Illuminate\Http\Request;

/** Painel central (proprietário: todas as unidades) e indicadores da loja (gerente: só a sua). */
class IndicadoresController extends Controller
{
    public function __invoke(Request $request, Indicadores $indicadores)
    {
        $user = $request->user();
        $dados = $request->validate(['de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:de'], 'unidade' => ['nullable', 'integer']]);
        $ate = $dados['ate'] ?? Fuso::exibir(now(), 'Y-m-d');
        $de = $dados['de'] ?? Fuso::exibir(now()->subDays(29), 'Y-m-d');
        $unidades = Unidade::visiveisPara($user)->orderBy('nome')->get();
        if (! empty($dados['unidade'])) {
            abort_unless($user->acessaUnidade((int) $dados['unidade']), 403);
            $unidades = $unidades->where('id', (int) $dados['unidade']);
        }
        $linhas = $indicadores->porUnidade($unidades->pluck('id')->all(), Fuso::inicioDoDia($de), Fuso::inicioDoDia($ate)->addDay());
        $total = ['por_etapa' => []];
        foreach ($linhas as $l) {
            foreach ($l as $k => $v) {
                if ($k !== 'por_etapa') {
                    $total[$k] = ($total[$k] ?? 0) + $v;
                }
            }
            foreach ($l['por_etapa'] as $s => $n) {
                $total['por_etapa'][$s] = ($total['por_etapa'][$s] ?? 0) + $n;
            }
        }

        return view('indicadores.index', compact('unidades', 'linhas', 'total', 'de', 'ate'));
    }
}
