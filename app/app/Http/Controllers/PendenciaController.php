<?php

namespace App\Http\Controllers;

use App\Jobs\EnviarMensagem;
use App\Models\MessageDelivery;
use App\Models\Ordem;
use App\Services\Auditoria;
use App\Services\Mensagens;
use App\Services\Pendencias;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class PendenciaController extends Controller
{
    public function index(Request $request, Pendencias $pendencias)
    {
        $user = $request->user();
        $aba = $request->query('aba') === 'mensagens' ? 'mensagens' : 'pendencias';
        $dados = ['aba' => $aba, 'simulado' => config('oficina.integracoes') === 'simulado'];

        if ($aba === 'pendencias') {
            $todas = $pendencias->listar($user, $request->boolean('resolvidas'));
            $pagina = LengthAwarePaginator::resolveCurrentPage();
            $dados['itens'] = (new LengthAwarePaginator($todas->forPage($pagina, 30)->values(), $todas->count(), 30, $pagina, ['path' => $request->url()]))->withQueryString();
            $dados['falhas'] = $todas->where('tipo', 'mensagem_falhou')->whereNull('resolucao')->count();
            $dados['abertas'] = $request->boolean('resolvidas') ? $todas->whereNull('resolucao')->count() : $todas->count();
        } else {
            $dados['mensagens'] = MessageDelivery::when(! $user->visaoGlobal(), fn ($q) => $q->whereIn('unidade_id', $user->unidadeIds()))
                ->orderByRaw("CASE status WHEN 'falhou' THEN 0 WHEN 'pendente' THEN 1 ELSE 2 END")->orderByDesc('id')
                ->paginate(30)->withQueryString();
            $dados['numeros'] = Ordem::whereIn('id', $dados['mensagens']->pluck('ordem_id'))->pluck('numero', 'id');
            $dados['maxTentativas'] = (new EnviarMensagem(0))->tries;
        }

        return view('pendencias.index', $dados);
    }

    public function resolver(Request $request, Pendencias $pendencias, string $tipo, int $id)
    {
        abort_unless(in_array($tipo, Pendencias::tiposResolviveis(), true), 404);
        $request->validate(['resolucao' => ['required', 'string', 'min:5', 'max:500']], [], ['resolucao' => 'resolução']);
        $pendencias->resolver($request->user(), $tipo, $id, $request->input('resolucao'));

        // Sem "mostrar resolvidas": o card resolvido sai da lista (antes descia para o fim e parecia que nada mudou).
        return redirect()->route('pendencias.index')->with('sucesso', 'Resolução registrada no histórico. A pendência saiu da lista.');
    }

    public function resolverMensagens(Request $request, Pendencias $pendencias)
    {
        $request->validate(['resolucao' => ['required', 'string', 'min:5', 'max:500']], [], ['resolucao' => 'resolução']);
        $n = $pendencias->resolverMensagensFalhas($request->user(), $request->input('resolucao'));

        return redirect()->route('pendencias.index')->with('sucesso', "{$n} mensagem(ns) não enviada(s) marcada(s) como resolvida(s).");
    }

    public function reenviar(Request $request, MessageDelivery $mensagem, Mensagens $mensagens)
    {
        abort_unless($mensagem->status === 'falhou', 404);
        $mensagens->reenviar($mensagem, $request->user()); // confere permissão e unidade

        return back()->with('sucesso', 'Mensagem colocada de volta na fila.');
    }

    public function processarFila(Request $request, Mensagens $mensagens)
    {
        $user = $request->user();
        $n = $mensagens->redespacharPendentes($user->visaoGlobal() ? null : $user->unidadeIds());
        Auditoria::registrar('fila_mensagens_processada', depois: ['redespachadas' => $n], ator: $user);

        return back()->with('sucesso', $n
            ? "{$n} mensagem(ns) parada(s) há mais de 5 minutos voltaram para a fila."
            : 'Nenhuma mensagem parada há mais de 5 minutos. As recentes já estão na fila.');
    }
}
