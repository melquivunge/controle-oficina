<?php

namespace App\Http\Controllers;

use App\Models\AprovacaoInterna;
use App\Models\Unidade;
use App\Services\AprovacoesInternas;
use Illuminate\Http\Request;

/** Pedidos de aprovação de desconto acima do limite (31/10/2026). Permissão, unidade e autoaprovação conferidas no serviço. */
class AprovacaoInternaController extends Controller
{
    public function __construct(private AprovacoesInternas $aprovacoes) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless(AprovacoesInternas::podeAprovar($user), 403);
        $decididas = AprovacaoInterna::with('ordem.veiculo', 'solicitante', 'decisor', 'unidade')
            ->whereIn('status', ['aprovada', 'recusada'])
            ->whereIn('unidade_id', Unidade::visiveisPara($user)->select('unidades.id'))
            ->orderByDesc('decidido_em')->limit(20)->get();

        return view('aprovacoes.index', ['pendentes' => $this->aprovacoes->pendentes($user), 'decididas' => $decididas]);
    }

    public function decidir(Request $request, AprovacaoInterna $aprovacao)
    {
        $dados = $request->validate([
            'decisao' => ['required', 'in:aprovada,recusada'],
            'justificativa' => ['required', 'string', 'min:5', 'max:500'],
        ], [], ['justificativa' => 'justificativa']);
        $this->aprovacoes->decidir($aprovacao, $request->user(), $dados['decisao'], $dados['justificativa']);

        return back()->with('sucesso', $dados['decisao'] === 'aprovada' ? 'Desconto aprovado. O orçamento já pode ser enviado ao cliente.' : 'Desconto recusado. Quem pediu verá a justificativa no orçamento.');
    }
}
