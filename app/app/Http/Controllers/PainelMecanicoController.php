<?php

namespace App\Http\Controllers;

use App\Enums\StatusOrdem;
use App\Models\AuditEvent;
use App\Models\Ordem;
use App\Support\Fuso;
use Illuminate\Http\Request;

/** Painel do colaborador: só prazos e andamento dos próprios serviços — nenhum valor nem contato do cliente. */
class PainelMecanicoController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $hoje = Fuso::exibir(now(), 'Y-m-d');
        $minhas = Ordem::visiveisPara($user)->with('veiculo')->where('mecanico_id', $user->id)->whereIn('status', StatusOrdem::abertas())
            ->orderByRaw('entrega_prevista_em ASC NULLS LAST')->orderBy('entrada_em')->get();
        $prontasSemana = AuditEvent::where('acao', 'ordem_status')->where('ator_id', $user->id)
            ->where('depois->status', StatusOrdem::ProntoParaEntrega->value)->where('ocorrido_em', '>=', Fuso::inicioDoDia($hoje)->subDays(6))->count();

        return view('mecanico.painel', [
            'minhas' => $minhas,
            'atrasadas' => $minhas->filter(fn ($o) => $o->entregaAtrasada()),
            'hoje' => $minhas->filter(fn ($o) => $o->entrega_prevista_em?->format('Y-m-d') === $hoje),
            'emExecucao' => $minhas->where('status', StatusOrdem::EmExecucao)->count(),
            'aguardando' => $minhas->whereIn('status', [StatusOrdem::Autorizado, StatusOrdem::Entrada, StatusOrdem::Diagnostico])->count(),
            'prontasSemana' => $prontasSemana,
            'livres' => Ordem::visiveisPara($user)->whereNull('mecanico_id')->where('status', StatusOrdem::Entrada)->count(),
        ]);
    }
}
