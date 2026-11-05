<?php

namespace App\Http\Controllers;

use App\Models\Ordem;
use App\Services\PrazosEntrega;
use Illuminate\Http\Request;

/** Define/altera a data de entrega prevista na OS (dias úteis ou data direta). Autorização no serviço (definirPrazo). */
class PrazoEntregaController extends Controller
{
    public function __invoke(Request $request, Ordem $ordem, PrazosEntrega $prazos)
    {
        $this->authorize('definirPrazo', $ordem);
        $dados = $request->validate([
            'prazo_dias' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('oficina.prazo_entrega_max_dias_uteis', 120)],
            'prazo_data' => ['nullable', 'date_format:Y-m-d'],
        ], [], ['prazo_dias' => 'prazo em dias úteis', 'prazo_data' => 'data de entrega']);
        $o = $prazos->definir($ordem, $request->user(), isset($dados['prazo_dias']) ? (int) $dados['prazo_dias'] : null, $dados['prazo_data'] ?? null);

        return back()->with('sucesso', 'Entrega prevista para '.$o->entrega_prevista_em->format('d/m/Y').'.');
    }
}
