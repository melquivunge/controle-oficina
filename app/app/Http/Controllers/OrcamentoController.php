<?php

namespace App\Http\Controllers;

use App\Models\Orcamento;
use App\Models\Ordem;
use App\Services\Orcamentos;
use Illuminate\Http\Request;

class OrcamentoController extends Controller
{
    public function __construct(private Orcamentos $orcamentos) {}

    /** Status filtráveis na lista global (rascunho fica só dentro da OS). */
    public const FILTROS = ['enviado' => 'Aguardando decisão', 'aprovado' => 'Aprovado', 'recusado' => 'Recusado', 'substituido' => 'Substituído', 'todos' => 'Todos (exceto rascunho)'];

    public function index(Request $request)
    {
        $user = $request->user();
        $filtros = $request->validate(['status' => ['nullable', 'in:'.implode(',', array_keys(self::FILTROS))], 'unidade' => ['nullable', 'integer']]);
        $status = $filtros['status'] ?? 'enviado';
        if (! empty($filtros['unidade'])) {
            abort_unless($user->acessaUnidade((int) $filtros['unidade']), 403);
        }

        $orcamentos = Orcamento::with('ordem.veiculo', 'ordem.unidade')
            ->where('status', '<>', 'rascunho')
            ->when(! $user->visaoGlobal(), fn ($q) => $q->whereIn('unidade_id', $user->unidadeIds()))
            ->when($status !== 'todos', fn ($q) => $q->where('status', $status))
            ->when($filtros['unidade'] ?? null, fn ($q, $u) => $q->where('unidade_id', $u))
            ->orderByRaw('enviado_em DESC NULLS LAST')->orderByDesc('id')
            ->paginate(30)->withQueryString();

        return view('orcamentos.index', [
            'orcamentos' => $orcamentos, 'status' => $status,
            'unidades' => \App\Models\Unidade::visiveisPara($user)->orderBy('nome')->get(),
        ]);
    }

    public function enviar(Request $request, Ordem $ordem, Orcamento $orcamento)
    {
        abort_unless($orcamento->ordem_id === $ordem->id, 404);
        $prazo = $this->prazoInformado($request, $ordem);
        $token = $this->orcamentos->enviar($orcamento, $request->user());
        $this->aplicarPrazo($request, $ordem, $prazo);

        return back()->with('sucesso', "Versão {$orcamento->versao} enviada.")->with('link_cliente', route('portal.show', $token));
    }

    public function presencial(Request $request, Ordem $ordem, Orcamento $orcamento)
    {
        abort_unless($orcamento->ordem_id === $ordem->id, 404);
        $dados = $request->validate([
            'decisao' => ['required', 'in:aprovado,recusado'],
            'observacao' => ['required', 'string', 'min:5', 'max:500'],
        ], [], ['observacao' => 'como o cliente decidiu']);
        $prazo = $this->prazoInformado($request, $ordem);
        $this->orcamentos->decidirPresencial($orcamento, $request->user(), $dados['decisao'], $dados['observacao']);
        if ($dados['decisao'] === 'aprovado') {
            $this->aplicarPrazo($request, $ordem, $prazo);
        }

        return back()->with('sucesso', $dados['decisao'] === 'aprovado' ? 'Aprovação registrada.' : 'Recusa registrada.');
    }

    public function novoLink(Request $request, Ordem $ordem)
    {
        $this->authorize('acompanharCliente', $ordem);
        $token = $this->orcamentos->novoLink($ordem, $request->user(), revogarAnteriores: true);

        return back()->with('sucesso', 'Novo link gerado. Os links anteriores deixaram de funcionar.')->with('link_cliente', route('portal.show', $token));
    }

    /**
     * Prazo opcional junto do envio/aprovação. Validado e autorizado ANTES da ação principal (só gerente/proprietário);
     * quem não pode definir prazo e o envia recebe 403.
     *
     * @return array{0:?int,1:?string}|null
     */
    private function prazoInformado(Request $request, Ordem $ordem): ?array
    {
        $dados = $request->validate([
            'prazo_dias' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('oficina.prazo_entrega_max_dias_uteis', 120)],
            'prazo_data' => ['nullable', 'date_format:Y-m-d'],
        ], [], ['prazo_dias' => 'prazo em dias úteis', 'prazo_data' => 'data de entrega']);
        $dias = isset($dados['prazo_dias']) ? (int) $dados['prazo_dias'] : null;
        $data = $dados['prazo_data'] ?? null;
        if ($dias === null && $data === null) {
            return null;
        }
        $this->authorize('definirPrazo', $ordem);
        app(\App\Services\PrazosEntrega::class)->calcular($dias, $data, $ordem->unidade_id); // valida antes (feriados da unidade) de enviar/aprovar

        return [$dias, $data];
    }

    private function aplicarPrazo(Request $request, Ordem $ordem, ?array $prazo): void
    {
        if ($prazo) {
            app(\App\Services\PrazosEntrega::class)->definir($ordem->fresh(), $request->user(), $prazo[0], $prazo[1]);
        }
    }

    public function revogarLinks(Request $request, Ordem $ordem)
    {
        $this->orcamentos->revogarLinks($ordem, $request->user());

        return back()->with('sucesso', 'Links do cliente revogados.');
    }
}
