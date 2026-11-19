<?php

namespace App\Http\Controllers;

use App\Integracoes\Fiscal\EmissorFiscal;
use App\Integracoes\Fiscal\EmissorReal;
use App\Integracoes\Fiscal\NfeSefaz;
use App\Models\CatalogoItem;
use App\Models\Cliente;
use App\Models\FiscalDocument;
use App\Models\NotaAvulsa;
use App\Models\Unidade;
use App\Services\Fiscal;
use App\Support\Dinheiro;
use App\Support\DocumentoFiscalHtml;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Nota de peças avulsa (19/11/2026): NF-e de venda ou remessa sem OS. Rotas com can:cobrar; unidade conferida aqui e no serviço. */
class NotaAvulsaController extends Controller
{
    private const LINHAS = 6;

    public function index(Request $request)
    {
        return view('notas-avulsas.index', ['notas' => $this->visiveis($request)->with('cliente', 'unidade', 'documentos')->orderByDesc('id')->paginate(30)]);
    }

    public function create(Request $request)
    {
        $cliente = $request->filled('cliente') ? Cliente::findOrFail((int) $request->query('cliente')) : null;
        $termo = trim((string) $request->query('q'));

        return view('notas-avulsas.nova', [
            'cliente' => $cliente,
            'falta' => $cliente && app(EmissorFiscal::class) instanceof EmissorReal ? NfeSefaz::faltaNoCliente($cliente) : null,
            'termo' => $termo,
            'encontrados' => ! $cliente && mb_strlen($termo) >= 3 ? Cliente::buscar($termo)->orderBy('nome')->limit(10)->get() : collect(),
            'unidades' => Unidade::visiveisPara($request->user())->where('ativa', true)->orderBy('nome')->get(),
            'catalogo' => CatalogoItem::where('ativo', true)->where('tipo', 'produto')->orderBy('nome')->get(['id', 'nome', 'preco_centavos', 'ncm']),
            'linhas' => self::LINHAS,
        ]);
    }

    public function store(Request $request, Fiscal $fiscal)
    {
        $user = $request->user();
        $d = $request->validate([
            'cliente_id' => ['required', 'integer', 'exists:clientes,id'],
            'unidade_id' => ['required', 'integer', Rule::in(Unidade::visiveisPara($user)->where('ativa', true)->pluck('id')->all())],
            'operacao' => ['required', Rule::in(array_keys(NotaAvulsa::OPERACOES))],
            'canal' => ['required', Rule::in(array_keys(NotaAvulsa::CANAIS))],
            'frete' => ['required', 'integer', Rule::in(array_keys(NotaAvulsa::FRETES))],
            'pagamento' => ['required', Rule::in(array_map('strval', array_keys(NotaAvulsa::PAGAMENTOS)))],
            'observacao' => ['nullable', 'string', 'max:500'],
            'itens' => ['required', 'array', 'max:'.self::LINHAS],
            'itens.*.catalogo_item_id' => ['nullable', 'integer'],
            'itens.*.descricao' => ['nullable', 'string', 'max:120'],
            'itens.*.ncm' => ['nullable', 'regex:/^\d{8}$/'],
            'itens.*.quantidade' => ['nullable', 'string', 'max:15'],
            'itens.*.preco' => ['nullable', 'string', 'max:20'],
        ], ['itens.*.ncm.regex' => 'NCM com 8 números.'], ['unidade_id' => 'unidade', 'operacao' => 'tipo de operação', 'canal' => 'como foi a venda']);

        $catalogo = CatalogoItem::where('tipo', 'produto')->whereIn('id', collect($d['itens'])->pluck('catalogo_item_id')->filter())->get()->keyBy('id');
        $itens = [];
        foreach ($d['itens'] as $k => $l) {
            $c = $catalogo[$l['catalogo_item_id'] ?? 0] ?? null;
            $descricao = trim((string) ($l['descricao'] ?? '')) ?: $c?->nome;
            if (! $descricao && blank($l['preco'] ?? null)) {
                continue; // linha vazia
            }
            try {
                $q = Dinheiro::quantidade((string) (($l['quantidade'] ?? '') ?: '1'));
                $preco = filled($l['preco'] ?? null) ? Dinheiro::deTexto((string) $l['preco']) : (int) $c?->precoNaUnidade((int) $d['unidade_id']);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(["itens.$k.preco" => 'Linha '.($k + 1).': '.$e->getMessage()]);
            }
            if (! $descricao || $preco < 1) {
                throw ValidationException::withMessages(["itens.$k.descricao" => 'Linha '.($k + 1).': informe a peça e o preço.']);
            }
            $itens[] = ['descricao' => $descricao, 'quantidade' => $q, 'preco_unitario_centavos' => $preco, 'ncm' => ($l['ncm'] ?? null) ?: $c?->ncm, 'codigo' => $c?->id];
        }
        if (! $itens) {
            throw ValidationException::withMessages(['itens' => 'Inclua ao menos uma peça.']);
        }
        $nota = $fiscal->solicitarAvulsa($user, ['itens' => $itens] + $d);

        return redirect()->route('notas-avulsas.show', $nota)->with('sucesso', 'NF-e de '.mb_strtolower(NotaAvulsa::OPERACOES[$nota->operacao]).' solicitada. O resultado aparece aqui em instantes.');
    }

    public function show(Request $request, NotaAvulsa $nota)
    {
        $this->exigirUnidade($request, $nota);

        return view('notas-avulsas.show', ['nota' => $nota->load('cliente', 'unidade', 'documentos')]);
    }

    public function reprocessar(Request $request, NotaAvulsa $nota, FiscalDocument $documento, Fiscal $fiscal)
    {
        $this->exigirDocumento($request, $nota, $documento);
        $fiscal->reprocessar($documento, $request->user());

        return back()->with('sucesso', 'Nova tentativa de emissão enviada.');
    }

    public function interromper(Request $request, NotaAvulsa $nota, FiscalDocument $documento, Fiscal $fiscal)
    {
        $this->exigirDocumento($request, $nota, $documento);

        return back()->with('sucesso', $fiscal->interromper($documento, $request->user()) ? 'Emissão interrompida. O sistema parou de tentar.' : 'A Fazenda já tinha esta nota.');
    }

    public function cancelar(Request $request, NotaAvulsa $nota, FiscalDocument $documento, Fiscal $fiscal)
    {
        $this->exigirDocumento($request, $nota, $documento);
        $v = Validator::make($request->only('motivo'), ['motivo' => ['required', 'string', 'min:15', 'max:255']], [], ['motivo' => 'justificativa'])->validate();
        $fiscal->cancelar($documento, $request->user(), $v['motivo']);

        return back()->with('sucesso', "NF-e {$documento->numero} cancelada.");
    }

    public function baixar(Request $request, NotaAvulsa $nota, FiscalDocument $documento)
    {
        $this->exigirDocumento($request, $nota, $documento);
        abort_unless($documento->arquivo || $documento->xml, 404);
        if ($request->boolean('xml') || ! $documento->arquivo) {
            abort_unless($documento->xml, 404);

            return response(Storage::disk('local')->get($documento->xml), 200, ['Content-Type' => 'application/xml', 'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'attachment; filename="'.($documento->chave_acesso ?: 'nota-'.$documento->id).'.xml"']);
        }

        return DocumentoFiscalHtml::resposta($documento, 'private, no-store');
    }

    private function visiveis(Request $request)
    {
        $user = $request->user();

        return NotaAvulsa::query()->when(! $user->visaoGlobal(), fn ($q) => $q->whereIn('unidade_id', $user->unidadeIds()));
    }

    private function exigirUnidade(Request $request, NotaAvulsa $nota): void
    {
        abort_unless($request->user()->acessaUnidade($nota->unidade_id), 403);
    }

    private function exigirDocumento(Request $request, NotaAvulsa $nota, FiscalDocument $documento): void
    {
        $this->exigirUnidade($request, $nota);
        abort_unless($documento->nota_avulsa_id === $nota->id, 404);
    }
}
