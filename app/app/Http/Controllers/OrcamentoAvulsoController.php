<?php

namespace App\Http\Controllers;

use App\Models\OrcamentoAvulso;
use App\Models\Unidade;
use App\Models\WhatsappConversa;
use App\Services\OrcamentosAvulsos;
use App\Support\Normaliza;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Orçamento sem entrada do veículo (10/11/2026). Rotas com can:orcar; unidade conferida no serviço em toda ação. */
class OrcamentoAvulsoController extends Controller
{
    public function __construct(private OrcamentosAvulsos $servico) {}

    public function index(Request $request)
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(array_keys(OrcamentoAvulso::SITUACOES + ['abertos' => 1, 'todos' => 1]))]])['status'] ?? 'abertos';

        return view('orcamentos-avulsos.index', [
            'status' => $status,
            'orcamentos' => OrcamentoAvulso::visiveisPara($request->user())->with('cliente', 'unidade', 'ordem')
                ->when($status === 'abertos', fn ($q) => $q->whereIn('status', ['rascunho', 'enviado', 'aprovado']))
                ->when(! in_array($status, ['abertos', 'todos'], true), fn ($q) => $q->where('status', $status))
                ->orderByDesc('id')->paginate(30)->withQueryString(),
        ]);
    }

    public function create(Request $request)
    {
        $conversa = $request->filled('conversa') ? WhatsappConversa::visiveisPara($request->user())->with('cliente')->findOrFail((int) $request->query('conversa')) : null;

        return view('orcamentos-avulsos.novo', ['conversa' => $conversa,
            'unidades' => Unidade::visiveisPara($request->user())->where('ativa', true)->orderBy('nome')->get()]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $request->merge(['telefone' => Normaliza::telefone($request->input('telefone')), 'placa' => Normaliza::placa($request->input('placa'))]);
        $d = $request->validate([
            'unidade_id' => ['required', 'integer', Rule::in(Unidade::visiveisPara($user)->where('ativa', true)->pluck('id')->all())],
            'conversa_id' => ['nullable', 'integer'],
            'telefone' => ['exclude_with:conversa_id', 'required', 'regex:/^\d{10,11}$/'],
            'nome' => ['exclude_with:conversa_id', 'required', 'string', 'min:2', 'max:150'],
            'placa' => ['nullable', 'regex:'.Normaliza::REGEX_PLACA],
            'veiculo_descricao' => ['nullable', 'string', 'max:120'],
        ], ['telefone.regex' => 'Informe o telefone com DDD (10 ou 11 dígitos).', 'placa.regex' => 'Placa inválida (ex.: ABC1D23).'],
            ['unidade_id' => 'unidade', 'veiculo_descricao' => 'veículo']);
        if (! empty($d['conversa_id'])) {
            WhatsappConversa::visiveisPara($user)->findOrFail($d['conversa_id']); // conversa que a pessoa pode ver
        }
        $o = $this->servico->criar($user, $d);

        return redirect()->route('orcamentos-avulsos.show', $o)->with('sucesso', "Orçamento {$o->numero} criado. Inclua os itens.");
    }

    public function show(Request $request, OrcamentoAvulso $orcamento)
    {
        $this->servico->autorizar($request->user(), $orcamento);

        return view('orcamentos-avulsos.show', ['o' => $orcamento->load('cliente', 'unidade', 'ordem', 'conversa')]);
    }

    /** Enviar: canal=loja (WhatsApp da loja/Central) ou manual (wa.me). Cada envio gera link novo; o anterior deixa de valer. */
    public function enviar(Request $request, OrcamentoAvulso $orcamento)
    {
        $canal = $request->validate(['canal' => ['required', 'in:loja,manual']])['canal'];
        $link = route('orcamento-avulso.portal', $this->servico->gerarLink($orcamento, $request->user()));
        $orcamento->refresh();
        if ($canal === 'manual') {
            return redirect()->away('https://wa.me/55'.Normaliza::telefone($orcamento->cliente->telefone).'?text='.rawurlencode($this->servico->texto($orcamento, $link)));
        }
        try {
            $this->servico->enviarPelaLoja($orcamento, $request->user(), $link);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['envio' => 'Não foi possível enviar pelo WhatsApp da loja ('.mb_substr($e->getMessage(), 0, 150).'). Use "Abrir no meu WhatsApp".']);
        }

        return back()->with('sucesso', $orcamento->conversa ? 'Orçamento enviado na conversa do WhatsApp.' : 'Orçamento enviado pelo WhatsApp da loja.');
    }

    public function decidir(Request $request, OrcamentoAvulso $orcamento)
    {
        $d = $request->validate(['decisao' => ['required', 'in:aprovado,recusado'], 'canal' => ['required', Rule::in(array_keys(OrcamentoAvulso::CANAIS))],
            'observacao' => ['nullable', 'string', 'max:500']]);
        $this->servico->decidir($orcamento, $request->user(), $d['decisao'], $d['canal'], $d['observacao'] ?? null);

        return back()->with('sucesso', $d['decisao'] === 'aprovado' ? 'Aprovação registrada. Quando o carro chegar, use "Dar entrada do veículo".' : 'Recusa registrada.');
    }

    public function cancelar(Request $request, OrcamentoAvulso $orcamento)
    {
        $motivo = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:300']])['motivo'];
        $this->servico->cancelar($orcamento, $request->user(), $motivo);

        return back()->with('sucesso', 'Orçamento cancelado. O link do cliente deixou de funcionar.');
    }

    /** Página do cliente: só leitura, token de 48 caracteres; inválido, vencido ou cancelado = 404 (sem enumeração). */
    public function portal(string $token)
    {
        $o = strlen($token) === 48 ? OrcamentoAvulso::with('cliente', 'unidade')->where('token_hash', hash('sha256', $token))->first() : null;
        abort_unless($o?->linkValido(), 404);

        return response()->view('orcamentos-avulsos.portal', ['o' => $o])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }
}
