<?php

namespace App\Http\Controllers;

use App\Models\Conferencia;
use App\Models\Divergencia;
use App\Models\Unidade;
use App\Services\Conferencias;
use Illuminate\Http\Request;

class ConferenciaController extends Controller
{
    public function index(Request $request, Conferencias $conferencias)
    {
        $user = $request->user();
        $unidades = Unidade::visiveisPara($user)->where('ativa', true)->orderBy('nome')->get();
        if ($unidades->isEmpty()) {
            return $this->semUnidade('Conferência física');
        }
        $unidadeId = $request->integer('unidade') ?: ($user->unidade_id ?? $unidades->first()?->id);
        abort_unless($unidadeId && $user->acessaUnidade($unidadeId), 403);

        return view('conferencia.index', [
            'unidades' => $unidades, 'unidadeId' => $unidadeId,
            'esperadas' => $conferencias->esperadas($unidadeId),
            'abertas' => Divergencia::with('ordem', 'revisor')->where('unidade_id', $unidadeId)->whereNull('resolvido_em')->orderByDesc('id')->get(),
            'resolvidas' => Divergencia::with('resolvidoPor')->where('unidade_id', $unidadeId)->whereNotNull('resolvido_em')->orderByDesc('resolvido_em')->limit(20)->get(),
            'ultimas' => Conferencia::where('unidade_id', $unidadeId)->orderByDesc('id')->limit(10)->get(),
        ]);
    }

    public function store(Request $request, Conferencias $conferencias)
    {
        $dados = $request->validate([
            'unidade' => ['required', 'integer'], 'presentes' => ['array'], 'presentes.*' => ['integer'], 'marcacao' => ['array'], 'marcacao.*' => ['in:presente,ausente'],
            'placas' => ['nullable', 'string', 'max:2000'], 'observacao' => ['nullable', 'string', 'max:500'],
        ], [], ['placas' => 'placas sem ordem']);
        $placas = preg_split('/[\s,;]+/', (string) ($dados['placas'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $c = $conferencias->registrar((int) $dados['unidade'], $request->user(), array_map('intval', [...($dados['presentes'] ?? []), ...array_keys(array_filter($dados['marcacao'] ?? [], fn ($m) => $m === 'presente'))]), $placas, $dados['observacao'] ?? null);
        $n = Divergencia::where('conferencia_id', $c->id)->count();

        return back()->with('sucesso', $n ? "Conferência registrada com {$n} divergência(s) para revisão do gerente." : 'Conferência registrada: tudo confere.');
    }

    public function resolver(Request $request, Divergencia $divergencia, Conferencias $conferencias)
    {
        $request->validate(['resolucao' => ['required', 'string', 'min:5', 'max:500']], [], ['resolucao' => 'resolução']);
        $conferencias->resolver($divergencia, $request->user(), $request->input('resolucao'));

        return back()->with('sucesso', 'Divergência resolvida.');
    }
}
