<?php

namespace App\Http\Controllers;

use App\Enums\Papel;
use App\Enums\StatusOrdem;
use App\Models\AuditEvent;
use App\Models\Ordem;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Auditoria;
use App\Services\Entradas;
use App\Services\Ordens;
use App\Services\PrazosEntrega;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrdemController extends Controller
{
    /** Eventos do histórico visíveis a quem não vê valores (mecânico): nenhum envolve preço, pagamento ou contato. */
    public const HISTORICO_OPERACIONAL = [
        'ordem_criada', 'ordem_criada_contingencia', 'ordem_status', 'ordem_atribuida', 'ordem_diagnostico',
        'anexo_adicionado', 'servico_concluido', 'prazo_entrega_definido',
    ];

    public function __construct(private Ordens $ordens) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $filtros = $request->validate([
            'status' => ['nullable', Rule::enum(StatusOrdem::class)],
            'unidade' => ['nullable', 'integer'],
            'busca' => ['nullable', 'string', 'max:100'],
            'atrasadas' => ['nullable', 'boolean'],
            // Filtro de data (gráfico do painel): dia local de Brasília; data = qual evento da OS caiu no intervalo.
            'de' => ['nullable', 'date_format:Y-m-d', 'required_with:ate,data'],
            'ate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:de'],
            'data' => ['nullable', 'in:'.implode(',', array_keys(self::FILTROS_DATA))],
            'responsavel' => ['nullable', 'integer'],
        ], [], ['de' => 'data inicial', 'ate' => 'data final', 'data' => 'tipo de data']);
        $porTelefone = $user->can('ver_contato_cliente');
        if (! empty($filtros['unidade'])) {
            abort_unless($user->acessaUnidade((int) $filtros['unidade']), 403);
        }

        $filtroData = null;
        if (! empty($filtros['de'])) {
            $tipo = $filtros['data'] ?? 'entrada';
            // Recebimento e aprovação revelam movimento financeiro: só quem vê valores (mecânico nunca).
            abort_if(in_array($tipo, ['pagamento', 'aprovacao'], true) && ! $user->can('ver_valores'), 403);
            $ate = $filtros['ate'] ?? $filtros['de'];
            abort_if(\Carbon\CarbonImmutable::parse($filtros['de'])->diffInDays(\Carbon\CarbonImmutable::parse($ate)) > PainelController::MAX_DIAS, 422, 'Período máximo de 2 anos.');
            $filtroData = ['tipo' => $tipo, 'de' => $filtros['de'], 'ate' => $ate,
                'inicio' => \App\Support\Fuso::inicioDoDia($filtros['de']), 'fim' => \App\Support\Fuso::inicioDoDia($ate)->addDay()];
        }
        $responsavelId = ! empty($filtros['responsavel']) ? (int) $filtros['responsavel'] : null;

        $ordens = Ordem::visiveisPara($user)->with('cliente', 'veiculo', 'unidade', 'mecanico')
            ->when($filtros['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filtros['unidade'] ?? null, fn ($q, $u) => $q->where('ordens.unidade_id', $u))
            ->when($filtros['busca'] ?? null, fn ($q, $b) => $q->where(fn ($q) => $q->where('numero', 'ilike', '%'.addcslashes($b, '%_\\').'%')
                ->orWhereHas('cliente', fn ($c) => $c->buscar($b, $porTelefone))
                ->orWhere('item_descricao', 'ilike', '%'.addcslashes($b, '%_\\').'%')
                ->when(\App\Support\Normaliza::placa($b) !== '', fn ($q) => $q->orWhereHas('veiculo', fn ($v) => $v->where('placa', 'like', '%'.\App\Support\Normaliza::placa($b).'%')))))
            ->when(! empty($filtros['atrasadas']), fn ($q) => $q->whereIn('ordens.id', app(PrazosEntrega::class)->consultaAtrasadas($user)->select('ordens.id')))
            ->when($filtroData, fn ($q, $f) => self::filtrarPorData($q, $f['tipo'], $f['inicio'], $f['fim']))
            ->when($responsavelId, fn ($q, $r) => $q->where('ordens.criado_por', $r))
            ->orderByDesc('entrada_em')->paginate(30)->withQueryString();

        if ($filtroData) {
            $f = fn (string $d) => \Carbon\CarbonImmutable::parse($d)->format('d/m/Y');
            $filtroData['texto'] = self::FILTROS_DATA[$filtroData['tipo']].' '.($filtroData['de'] === $filtroData['ate'] ? 'em '.$f($filtroData['de']) : 'de '.$f($filtroData['de']).' a '.$f($filtroData['ate']));
        }
        // Nome de quem registrou a entrada: só a partir de uma OS que o usuário já pode ver (não revela usuários de outras unidades).
        $responsavelNome = $responsavelId ? Ordem::visiveisPara($user)->where('criado_por', $responsavelId)->with('autor:id,name')->first()?->autor?->name : null;

        return view('ordens.index', ['ordens' => $ordens, 'unidades' => Unidade::visiveisPara($user)->orderBy('nome')->get(),
            'filtroData' => $filtroData, 'responsavelId' => $responsavelId, 'responsavelNome' => $responsavelNome]);
    }

    /** Tipos de data do filtro (mesmos eventos das séries do painel, SeriesPainel::diarias). */
    public const FILTROS_DATA = [
        'entrada' => 'Ordens com entrada',
        'entrega' => 'Ordens entregues',
        'pagamento' => 'Ordens com recebimento ou devolução',
        'aprovacao' => 'Ordens com orçamento aprovado',
    ];

    /** Mesmo critério das séries do painel: instantes em UTC entre o início do dia local de "de" e o fim do dia de "até". */
    private static function filtrarPorData($q, string $tipo, \Carbon\CarbonImmutable $inicio, \Carbon\CarbonImmutable $fim)
    {
        $existe = fn (string $tabela, string $coluna, ?\Closure $extra = null) => fn ($s) => $s->selectRaw('1')->from($tabela)
            ->whereColumn("$tabela.ordem_id", 'ordens.id')->where("$tabela.$coluna", '>=', $inicio)->where("$tabela.$coluna", '<', $fim)
            ->when($extra, fn ($s) => $extra($s));

        return match ($tipo) {
            'entrada' => $q->where('ordens.entrada_em', '>=', $inicio)->where('ordens.entrada_em', '<', $fim),
            'entrega' => $q->whereExists($existe('entregas', 'entregue_em')),
            'aprovacao' => $q->whereExists($existe('aprovacoes', 'decidido_em', fn ($s) => $s->where('decisao', 'aprovado'))),
            'pagamento' => $q->where(fn ($q) => $q->whereExists($existe('pagamentos', 'recebido_em', fn ($s) => $s->whereIn('status', ['confirmado', 'registrado'])))
                ->orWhereExists($existe('devolucoes', 'concluida_em', fn ($s) => $s->where('status', 'concluida')))),
        };
    }

    public function show(Request $request, Ordem $ordem)
    {
        $this->authorize('view', $ordem);
        $ordem->load('cliente', 'veiculo', 'unidade', 'mecanico', 'autor', 'anexos', 'prazoDefinidoPor', 'vendedor');
        $historico = AuditEvent::with('ator')->where('entidade_tipo', 'ordem')->where('entidade_id', $ordem->id)->orderBy('id')->get();
        if (! $request->user()->can('ver_valores')) {
            // Colaborador: só eventos operacionais; motivos de entrega/cancelamento (podem citar valores) ficam ocultos.
            $historico = $historico->filter(fn (AuditEvent $h) => in_array($h->acao, self::HISTORICO_OPERACIONAL, true))
                ->each(function (AuditEvent $h) {
                    if ($h->acao === 'ordem_status' && in_array($h->depois['status'] ?? null, [StatusOrdem::Entregue->value, StatusOrdem::Cancelado->value], true)) {
                        $h->motivo = null;
                    }
                })->values();
        }

        return view('ordens.show', [
            'ordem' => $ordem,
            'historico' => $historico,
            'mecanicos' => User::where('papel', Papel::Mecanico)->where('unidade_id', $ordem->unidade_id)->where('ativo', true)->orderBy('name')->get(),
        ]);
    }

    public function atribuir(Request $request, Ordem $ordem)
    {
        $dados = $request->validate(['mecanico_id' => ['required', 'integer']], [], ['mecanico_id' => 'mecânico']);
        $mecanico = User::find($dados['mecanico_id']) ?? abort(422);
        $this->ordens->atribuirMecanico($ordem, $mecanico, $request->user());

        return back()->with('sucesso', "Ordem atribuída a {$mecanico->name}.");
    }

    public function iniciarDiagnostico(Request $request, Ordem $ordem)
    {
        $this->ordens->iniciarDiagnostico($ordem, $request->user());

        return back()->with('sucesso', 'Diagnóstico iniciado.');
    }

    public function diagnostico(Request $request, Ordem $ordem)
    {
        $dados = $request->validate(['diagnostico' => ['required', 'string', 'min:3', 'max:5000']], [], ['diagnostico' => 'diagnóstico']);
        $this->ordens->registrarDiagnostico($ordem, $dados['diagnostico'], $request->user());

        return back()->with('sucesso', 'Diagnóstico salvo.');
    }

    public function cancelar(Request $request, Ordem $ordem)
    {
        $dados = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']], [], ['motivo' => 'motivo do cancelamento']);
        $this->ordens->cancelar($ordem, $dados['motivo'], $request->user());

        return back()->with('sucesso', 'Ordem cancelada. Valores recebidos, se houver, não são estornados automaticamente.');
    }

    public function anexar(Request $request, Ordem $ordem, Entradas $entradas)
    {
        $this->authorize('anexar', $ordem);
        $request->validate(['foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192']], [], ['foto' => 'foto']);
        $anexo = $entradas->anexar($ordem, $request->file('foto'), $request->user());
        Auditoria::registrar('anexo_adicionado', $ordem, depois: ['anexo_id' => $anexo->id, 'nome' => $anexo->nome_original]);

        return back()->with('sucesso', 'Foto anexada.');
    }
}
