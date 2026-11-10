<?php

namespace App\Livewire;

use App\Models\CatalogoItem;
use App\Models\Orcamento;
use App\Models\Ordem;
use App\Services\AprovacoesInternas;
use App\Services\Catalogo;
use App\Services\Orcamentos;
use App\Support\Dinheiro;
use Livewire\Attributes\Locked;
use Livewire\Component;

class OrcamentoEditor extends Component
{
    #[Locked]
    public ?int $ordemId = null;

    /** Orçamento sem entrada (10/11/2026): mesmo editor, sem desconto (desconto só na OS). */
    #[Locked]
    public ?int $avulsoId = null;

    public string $validade = '';

    public array $itens = [];
    public string $desconto = '';
    public string $desconto_motivo = '';
    public string $observacao = '';
    public string $catalogoEscolhido = '';

    public function mount(?Ordem $ordem = null, ?\App\Models\OrcamentoAvulso $avulso = null): void
    {
        if ($avulso?->exists) {
            app(\App\Services\OrcamentosAvulsos::class)->autorizar(auth()->user(), $avulso);
            $this->avulsoId = $avulso->id;
            $this->itens = array_map(fn ($i) => ['descricao' => $i['descricao'], 'quantidade' => Dinheiro::quantidadeExibicao((string) $i['quantidade']),
                'preco' => Dinheiro::paraCampo((int) $i['preco_unitario_centavos']), 'catalogo_item_id' => $i['catalogo_item_id'] ?? null, 'desconto' => '', 'tipo' => $i['tipo'] ?? 'servico'], $avulso->itens ?? []);
            $this->observacao = (string) $avulso->observacao;
            $this->validade = $avulso->validade->toDateString();
            if (! $this->itens) {
                $this->adicionarLinha();
            }

            return;
        }
        $this->authorize('orcar', $ordem);
        $this->ordemId = $ordem->id;
        // Começa do rascunho; senão, da versão mais recente (para ajustes/extras); senão, em branco.
        $base = Orcamento::where('ordem_id', $ordem->id)->where('status', 'rascunho')->first()
            ?? Orcamento::where('ordem_id', $ordem->id)->whereIn('status', ['enviado', 'aprovado'])->orderByDesc('versao')->first();
        $this->itens = $base?->itens->map(fn ($i) => ['descricao' => $i->descricao, 'quantidade' => Dinheiro::quantidadeExibicao($i->quantidade), 'preco' => Dinheiro::paraCampo($i->preco_unitario_centavos),
            'catalogo_item_id' => $i->catalogo_item_id, 'desconto' => '', 'tipo' => $i->tipo ?? 'servico'])->all() ?? [];
        if ($base?->status === 'rascunho') {
            $this->desconto = $base->desconto_centavos ? Dinheiro::paraCampo($base->desconto_centavos) : '';
            $this->desconto_motivo = (string) $base->desconto_motivo;
            $this->observacao = (string) $base->observacao_cliente;
        }
        if (! $this->itens) {
            $this->adicionarLinha();
        }
    }

    public function adicionarLinha(): void
    {
        $this->itens[] = ['descricao' => '', 'quantidade' => '1', 'preco' => '', 'catalogo_item_id' => null, 'desconto' => '', 'tipo' => 'servico'];
    }

    /** Inclui item do catálogo (preço de tabela da unidade da ordem). Sem id, usa o escolhido na lista. */
    public function incluirCatalogo(?int $id = null): void
    {
        $id ??= (int) $this->catalogoEscolhido;
        $cat = $id ? CatalogoItem::where('ativo', true)->find($id) : null;
        if (! $cat) {
            $this->addError('catalogoEscolhido', 'Escolha um item ativo do catálogo.');

            return;
        }
        $linha = ['descricao' => $cat->nome, 'quantidade' => '1', 'preco' => Dinheiro::paraCampo($cat->precoNaUnidade($this->unidadeId())), 'catalogo_item_id' => $cat->id, 'desconto' => '', 'tipo' => $cat->tipo ?? 'servico'];
        $ultima = end($this->itens);
        if ($ultima && trim($ultima['descricao'] ?? '') === '' && trim($ultima['preco'] ?? '') === '' && empty($ultima['catalogo_item_id'])) {
            $this->itens[array_key_last($this->itens)] = $linha;
        } else {
            $this->itens[] = $linha;
        }
        $this->catalogoEscolhido = '';
    }

    /** Desconto % do item recalcula o preço sobre a tabela (arredondado para cima: o % efetivo nunca passa do digitado). */
    public function updatedItens($valor, $chave): void
    {
        [$i, $campo] = array_pad(explode('.', (string) $chave, 2), 2, null);
        if (! isset($this->itens[$i]) || empty($this->itens[$i]['catalogo_item_id'])) {
            return;
        }
        if ($campo === 'desconto') {
            try {
                $pct = Catalogo::percentual((string) $valor) ?? '0';
            } catch (\InvalidArgumentException) {
                $this->addError("itens.$i.desconto", 'Percentual entre 0 e 100.');

                return;
            }
            $cat = CatalogoItem::find((int) $this->itens[$i]['catalogo_item_id']);
            if ($cat) {
                $tabela = $cat->precoNaUnidade($this->unidadeId());
                $preco = (int) bcadd(bcdiv(bcadd(bcmul((string) $tabela, bcsub('100', $pct, 2), 2), '99.99', 2), '100', 2), '0', 0);
                $this->itens[$i]['preco'] = Dinheiro::paraCampo($preco);
            }
        } elseif ($campo === 'preco') {
            $this->itens[$i]['desconto'] = '';
        }
    }

    private function unidadeId(): int
    {
        if ($this->avulsoId) {
            return (int) \App\Models\OrcamentoAvulso::whereKey($this->avulsoId)->value('unidade_id');
        }

        return (int) Ordem::whereKey($this->ordemId)->value('unidade_id');
    }

    public function removerLinha(int $i): void
    {
        unset($this->itens[$i]);
        $this->itens = array_values($this->itens);
        if (! $this->itens) {
            $this->adicionarLinha();
        }
    }

    public function salvar(Orcamentos $orcamentos)
    {
        $this->resetErrorBag();
        if ($this->avulsoId) {
            $a = app(\App\Services\OrcamentosAvulsos::class)->salvarItens(\App\Models\OrcamentoAvulso::findOrFail($this->avulsoId), auth()->user(), $this->itens,
                $this->observacao ?: null, $this->validade ?: null);
            session()->flash('sucesso', 'Orçamento salvo. Confira e envie ao cliente.');

            return $this->redirectRoute('orcamentos-avulsos.show', $a);
        }
        try {
            $desconto = trim($this->desconto) === '' ? 0 : Dinheiro::deTexto($this->desconto);
        } catch (\InvalidArgumentException) {
            $this->addError('desconto', 'Desconto inválido. Exemplo: 20,00');

            return null;
        }
        $orcamentos->salvarRascunho(Ordem::findOrFail($this->ordemId), auth()->user(), $this->itens, $desconto, $this->desconto_motivo ?: null, $this->observacao ?: null);
        session()->flash('sucesso', 'Rascunho do orçamento salvo. Revise e aprove.');

        return $this->redirectRoute('ordens.show', Ordem::findOrFail($this->ordemId));
    }

    /** Prévia dos totais com a mesma regra do servidor; o valor gravado é sempre recalculado no serviço. */
    public function render()
    {
        $subtotal = 0;
        $linhas = [];
        foreach ($this->itens as $i => $item) {
            try {
                $linhas[$i] = Dinheiro::totalItem(Dinheiro::quantidade((string) $item['quantidade']), Dinheiro::deTexto((string) $item['preco']));
                $subtotal += $linhas[$i];
            } catch (\InvalidArgumentException) {
                $linhas[$i] = null;
            }
        }
        try {
            $desc = trim($this->desconto) === '' ? 0 : Dinheiro::deTexto($this->desconto);
        } catch (\InvalidArgumentException) {
            $desc = 0;
        }

        // Catálogo: preço de tabela, limite e desconto efetivo (item + geral rateado) por linha; adicionais sugeridos.
        $unidadeId = $this->unidadeId();
        $ids = collect($this->itens)->pluck('catalogo_item_id')->filter()->map(fn ($v) => (int) $v)->unique()->all();
        $usados = CatalogoItem::with(['precosUnidade', 'adicionais' => fn ($q) => $q->where('ativo', true)])->whereIn('id', $ids)->get()->keyBy('id');
        $info = [];
        $acimaDoLimite = false;
        $regra = AprovacoesInternas::regraGeral();
        foreach ($this->itens as $i => $item) {
            $cat = $usados->get((int) ($item['catalogo_item_id'] ?? 0));
            try {
                $preco = Dinheiro::deTexto((string) $item['preco']);
            } catch (\InvalidArgumentException) {
                $preco = null;
            }
            $base = $cat ? $cat->precoNaUnidade($unidadeId) : $preco;
            $limite = $cat ? $cat->limiteDesconto() : $regra;
            $excede = $preco !== null && $base !== null && $desc <= $subtotal && AprovacoesInternas::excede($preco, $base, $limite, $subtotal, $desc);
            $acimaDoLimite = $acimaDoLimite || $excede;
            $info[$i] = ['cat' => $cat, 'tabela' => $cat ? $base : null, 'limite' => $limite, 'excede' => $excede,
                'efetivo' => ($preco !== null && $base) ? AprovacoesInternas::percentualEfetivo($preco, $base, $subtotal, $desc) : '0.00'];
        }
        $sugeridos = $usados->flatMap(fn ($c) => $c->adicionais)->unique('id')->reject(fn ($a) => in_array($a->id, $ids, true))->values();
        $opcoes = CatalogoItem::where('ativo', true)->with('precosUnidade')->orderBy('categoria')->orderBy('nome')->get()->groupBy('categoria');

        return view('livewire.orcamento-editor', ['linhas' => $linhas, 'subtotal' => $subtotal, 'descontoCentavos' => $desc, 'podeDesconto' => ! $this->avulsoId && auth()->user()->pode('aplicar_desconto'),
            'info' => $info, 'acimaDoLimite' => $acimaDoLimite, 'sugeridos' => $sugeridos, 'opcoesCatalogo' => $opcoes, 'unidadeId' => $unidadeId, 'regraGeral' => $regra]);
    }
}
