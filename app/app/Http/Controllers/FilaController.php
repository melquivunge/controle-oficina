<?php

namespace App\Http\Controllers;

use App\Enums\StatusOrdem;
use App\Models\Ordem;
use Illuminate\Http\Request;

/** Fila do mecânico: ordens atribuídas a ele e ordens da unidade aguardando diagnóstico sem responsável. */
class FilaController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $base = Ordem::visiveisPara($user)->with('cliente', 'veiculo', 'unidade')->whereIn('status', StatusOrdem::abertas());

        return view('ordens.fila', [
            // Prazo de entrega primeiro (atrasadas no topo); sem prazo por último, por ordem de chegada.
            'minhas' => (clone $base)->where('mecanico_id', $user->id)->orderByRaw('entrega_prevista_em ASC NULLS LAST')->orderBy('entrada_em')->get(),
            'livres' => (clone $base)->whereNull('mecanico_id')->where('status', StatusOrdem::Entrada)->orderBy('entrada_em')->get(),
        ]);
    }
}
