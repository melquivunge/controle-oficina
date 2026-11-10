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
            ->when($placa !== '', fn ($q) => $q->where(fn ($w) => $w->where('placa', 'like', '%'.$placa.'%')->orWhere('chassi', 'like', '%'.$placa.'%')))
            ->orderBy('placa')->paginate(24)->withQueryString();

        // Últimas 3 ordens visíveis por veículo da página (uma consulta).
        $ordens = Ordem::visiveisPara($user)->whereIn('veiculo_id', $veiculos->pluck('id'))
            ->orderByDesc('entrada_em')->get(['id', 'numero', 'veiculo_id', 'status', 'entrada_em'])
            ->groupBy('veiculo_id')->map->take(3);

        return view('veiculos.index', compact('veiculos', 'ordens', 'placa'));
    }

    /** Chassi do veículo pela OS (quem registra entrada na unidade da OS). Auditado; vazio apaga. */
    public function chassi(Request $request, \App\Models\Ordem $ordem)
    {
        $this->authorize('atribuir', $ordem);
        abort_unless($ordem->veiculo_id, 404);
        $veiculo = $ordem->veiculo;
        $request->merge(['chassi' => Normaliza::chassi($request->input('chassi'))]);
        $chassi = $request->validate(['chassi' => ['nullable', 'regex:'.Normaliza::REGEX_CHASSI, \Illuminate\Validation\Rule::unique('veiculos', 'chassi')->ignore($veiculo->id)]],
            ['chassi.regex' => 'Chassi deve ter 17 letras e números (sem I, O ou Q).', 'chassi.unique' => 'Este chassi já está cadastrado em outro veículo.'])['chassi'] ?: null;
        if ($chassi !== $veiculo->chassi) {
            $antes = ['chassi' => $veiculo->chassi];
            $veiculo->update(['chassi' => $chassi]);
            \App\Services\Auditoria::registrar('veiculo_chassi_alterado', $veiculo, $antes, ['chassi' => $chassi, 'ordem' => $ordem->numero], unidadeId: $ordem->unidade_id);
        }

        return back()->with('sucesso', $chassi ? 'Chassi salvo no veículo.' : 'Chassi removido do veículo.');
    }
}
