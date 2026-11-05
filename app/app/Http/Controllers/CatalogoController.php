<?php

namespace App\Http\Controllers;

use App\Models\CatalogoItem;
use App\Models\Unidade;
use App\Services\Catalogo;
use Illuminate\Http\Request;

/** Catálogo de serviços e produtos (31/10/2026). Autorização e regras em App\Services\Catalogo. */
class CatalogoController extends Controller
{
    public function __construct(private Catalogo $catalogo) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless(Catalogo::podeGerir($user), 403);
        $filtros = $request->validate(['busca' => ['nullable', 'string', 'max:100'], 'categoria' => ['nullable', 'string', 'max:60'], 'situacao' => ['nullable', 'in:ativos,inativos,todos']]);
        $situacao = $filtros['situacao'] ?? 'ativos';
        $itens = CatalogoItem::withCount('adicionais')
            ->when($filtros['busca'] ?? null, fn ($q, $b) => $q->where('nome', 'ilike', '%'.addcslashes($b, '%_\\').'%'))
            ->when($filtros['categoria'] ?? null, fn ($q, $c) => $q->where('categoria', $c))
            ->when($situacao !== 'todos', fn ($q) => $q->where('ativo', $situacao === 'ativos'))
            ->orderBy('categoria')->orderBy('nome')->paginate(50)->withQueryString();

        return view('catalogo.index', [
            'itens' => $itens, 'situacao' => $situacao,
            'categorias' => CatalogoItem::query()->distinct()->orderBy('categoria')->pluck('categoria'),
            'podeLimite' => Catalogo::podeDefinirLimite($user),
            'relatorio' => session('relatorio_importacao'),
        ]);
    }

    public function store(Request $request)
    {
        $item = $this->catalogo->salvar($request->user(), $this->dados($request));

        return redirect()->route('catalogo.edit', $item)->with('sucesso', "Item “{$item->nome}” incluído no catálogo.");
    }

    public function edit(Request $request, CatalogoItem $item)
    {
        $user = $request->user();
        abort_unless(Catalogo::podeGerir($user), 403);

        return view('catalogo.editar', [
            'item' => $item->load('adicionais', 'precosUnidade', 'historico.autor', 'historico.unidade'),
            'outros' => CatalogoItem::where('id', '<>', $item->id)->orderBy('categoria')->orderBy('nome')->get(),
            'unidades' => Unidade::visiveisPara($user)->orderBy('nome')->get(),
            'podeLimite' => Catalogo::podeDefinirLimite($user),
        ]);
    }

    public function update(Request $request, CatalogoItem $item)
    {
        $this->catalogo->salvar($request->user(), $this->dados($request, true), $item);

        return back()->with('sucesso', 'Item do catálogo atualizado.');
    }

    public function importar(Request $request)
    {
        abort_unless(Catalogo::podeGerir($request->user()), 403);
        $request->validate(['arquivo' => ['required', 'file', 'max:1024', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel']], [], ['arquivo' => 'arquivo CSV']);
        $relatorio = $this->catalogo->importarCsv($request->user(), (string) file_get_contents($request->file('arquivo')->getRealPath()));

        return redirect()->route('catalogo.index')->with('relatorio_importacao', $relatorio)
            ->with('sucesso', "Importação concluída: {$relatorio['criados']} novos, {$relatorio['atualizados']} atualizados, ".count($relatorio['recusadas']).' linhas recusadas.');
    }

    private function dados(Request $request, bool $edicao = false): array
    {
        $dados = $request->only('nome', 'categoria', 'unidade_venda', 'preco');
        $dados['ativo'] = $request->boolean('ativo', ! $edicao);
        if ($request->has('desconto_max')) {
            $dados['desconto_max'] = (string) $request->input('desconto_max');
        }
        if ($edicao) {
            $dados['adicionais'] = (array) $request->input('adicionais', []);
            $dados['precos_unidade'] = array_filter((array) $request->input('precos_unidade', []), fn ($v) => is_scalar($v) || $v === null);
        }

        return $dados;
    }
}
