<?php

namespace App\Http\Controllers;

use App\Models\Ordem;
use App\Models\Veiculo;
use App\Support\Normaliza;
use Illuminate\Http\Request;

/** Veículos com atendimento nas unidades visíveis ao usuário, com as últimas ordens de cada um. */
class VeiculoController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $placa = Normaliza::placa($request->validate(['placa' => ['nullable', 'string', 'max:20']])['placa'] ?? '');
        $visiveis = fn ($q) => $q->visiveisPara($user);

        $veiculos = Veiculo::with('cliente')
            ->where(fn ($q) => $q->whereHas('ordens', $visiveis)->orWhereDoesntHave('ordens')) // + sem atendimento (importados/cadastro direto)
            ->withCount(['ordens' => $visiveis])
            ->when($placa !== '', fn ($q) => $q->where('placa', 'like', '%'.$placa.'%'))
            ->orderBy('placa')->paginate(24)->withQueryString();

        // Últimas 3 ordens visíveis por veículo da página (uma consulta).
        $ordens = Ordem::visiveisPara($user)->whereIn('veiculo_id', $veiculos->pluck('id'))
            ->orderByDesc('entrada_em')->get(['id', 'numero', 'veiculo_id', 'status', 'entrada_em'])
            ->groupBy('veiculo_id')->map->take(3);

        return view('veiculos.index', compact('veiculos', 'ordens', 'placa'));
    }
}
