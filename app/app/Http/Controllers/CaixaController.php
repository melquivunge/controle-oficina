<?php

namespace App\Http\Controllers;

use App\Models\FechamentoCaixa;
use App\Models\Unidade;
use App\Services\Financeiro;
use App\Support\Dinheiro;
use App\Support\Fuso;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CaixaController extends Controller
{
    public function index(Request $request, Financeiro $financeiro)
    {
        $user = $request->user();
        $dados = $request->validate(['unidade' => ['nullable', 'integer'], 'data' => ['nullable', 'date_format:Y-m-d']]);
        $unidades = Unidade::visiveisPara($user)->orderBy('nome')->get();
        if ($unidades->isEmpty()) {
            return $this->semUnidade('Caixa e fechamento');
        }
        $unidadeId = (int) ($dados['unidade'] ?? $user->unidade_id ?? $unidades->first()?->id);
        abort_unless($unidadeId && $user->acessaUnidade($unidadeId), 403);
        $data = $dados['data'] ?? Fuso::exibir(now(), 'Y-m-d');

        return view('caixa.index', [
            'unidades' => $unidades, 'unidadeId' => $unidadeId, 'data' => $data,
            'recebimentos' => $financeiro->recebimentosDoDia($unidadeId, $data),
            'dinheiroEsperado' => $financeiro->dinheiroEsperado($unidadeId, $data),
            'devolvidoDinheiro' => $financeiro->devolvidoEmDinheiro($unidadeId, $data),
            // ponytail: saldo calculado ordem a ordem (4 consultas cada); agregar em SQL se a lista passar de centenas
            'aReceber' => \App\Models\Ordem::with('veiculo', 'cliente')->where('unidade_id', $unidadeId)
                ->whereIn('status', \App\Enums\StatusOrdem::recebiveis())->whereHas('orcamentos', fn ($q) => $q->where('status', 'aprovado'))
                ->orderBy('entrada_em')->get()->map(fn ($o) => [$o, $financeiro->resumo($o)])->filter(fn ($l) => $l[1]['saldo'] > 0)
                ->map(fn ($l) => [$l[0], $l[1], app(\App\Services\Combinados::class)->situacao($l[0], $l[1])])->values(), // + combinado (próxima parcela e vencimento)
            'fechamento' => FechamentoCaixa::where('unidade_id', $unidadeId)->where('data', $data)->first(),
        ]);
    }

    public function fechar(Request $request, Financeiro $financeiro)
    {
        $dados = $request->validate(['unidade' => ['required', 'integer'], 'data' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'contado' => ['required', 'string'], 'observacao' => ['nullable', 'string', 'max:500']]);
        try {
            $contado = Dinheiro::deTexto($dados['contado']);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['contado' => 'Informe o valor contado. Exemplo: 350,00']);
        }
        $f = $financeiro->fecharCaixa((int) $dados['unidade'], $dados['data'], $request->user(), $contado, $dados['observacao'] ?? null);
        $dif = $f->dinheiro_contado_centavos - $f->dinheiro_sistema_centavos;

        return back()->with('sucesso', 'Caixa fechado.'.($dif !== 0 ? ' Diferença no dinheiro: '.Dinheiro::formatar($dif).' (registrada para revisão).' : ''));
    }
}
