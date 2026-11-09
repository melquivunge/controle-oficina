@extends('layouts.app', ['titulo' => 'Catálogo'])
@section('conteudo')
    <div class="page-head"><div><h1>{{ $item->nome }}</h1><p class="subtitle">{{ $item->categoria }} · {{ $item->rotuloUnidade() }} · <a href="{{ route('catalogo.index') }}">voltar ao catálogo</a></p></div></div>
    <form method="POST" action="{{ route('catalogo.update', $item) }}" class="form-panel">
        @csrf @method('PUT')
        <div class="form-section">
            <h2>Dados e preço</h2>
            <div class="fields">
                @include('partials.campo', ['nome' => 'nome', 'rotulo' => 'Nome', 'valorAtual' => $item->nome, 'obrigatorio' => true, 'atributos' => ['maxlength' => 150]])
                @include('partials.campo', ['nome' => 'categoria', 'rotulo' => 'Categoria', 'valorAtual' => $item->categoria, 'obrigatorio' => true, 'atributos' => ['maxlength' => 60]])
                @include('partials.campo', ['nome' => 'unidade_venda', 'rotulo' => 'Unidade de venda', 'tipo' => 'select', 'opcoes' => \App\Models\CatalogoItem::UNIDADES_VENDA, 'valorAtual' => $item->unidade_venda])
                @include('partials.campo', ['nome' => 'tipo', 'rotulo' => 'Tipo (define a nota fiscal)', 'tipo' => 'select', 'opcoes' => \App\Support\DadosFiscais::TIPOS_ITEM, 'valorAtual' => $item->tipo, 'ajuda' => 'Serviço sai na NFS-e; peça/produto sai na NF-e.'])
                @include('partials.campo', ['nome' => 'ncm', 'rotulo' => 'NCM (só peça/produto)', 'valorAtual' => $item->ncm, 'atributos' => ['inputmode' => 'numeric', 'maxlength' => 10, 'placeholder' => '8708.70.90']])
                @include('partials.campo', ['nome' => 'preco', 'rotulo' => 'Preço padrão (R$)', 'valorAtual' => \App\Support\Dinheiro::paraCampo($item->preco_centavos), 'obrigatorio' => true, 'atributos' => ['inputmode' => 'decimal']])
                @if ($podeLimite)
                    @include('partials.campo', ['nome' => 'desconto_max', 'rotulo' => 'Desconto máximo sem aprovação (%)', 'valorAtual' => $item->desconto_max_percentual === null ? '' : str_replace('.', ',', $item->desconto_max_percentual), 'ajuda' => 'Em branco: regra geral de '.str_replace('.', ',', \App\Services\AprovacoesInternas::regraGeral()).'%.', 'atributos' => ['inputmode' => 'decimal']])
                @else
                    <p class="hint">Desconto máximo: {{ $item->desconto_max_percentual === null ? 'regra geral' : str_replace('.', ',', $item->desconto_max_percentual).'%' }} (definido pelo proprietário ou por quem aprova descontos).</p>
                @endif
            </div>
            <div class="field"><label class="check"><input type="hidden" name="ativo" value="0"><input type="checkbox" name="ativo" value="1" @checked(old('ativo', $item->ativo))> Ativo (aparece no orçamento)</label></div>
        </div>
        <div class="form-section">
            <h2>Preço por unidade (opcional)</h2>
            <p class="hint">Em branco: vale o preço padrão. Só aparecem as unidades em que você atua.</p>
            <div class="fields">
                @foreach ($unidades as $un)
                    @php($ov = $item->precosUnidade->firstWhere('unidade_id', $un->id))
                    @include('partials.campo', ['nome' => "precos_unidade[{$un->id}]", 'id' => 'preco-unidade-'.$un->id, 'rotulo' => $un->nome.' (R$)', 'valorAtual' => $ov ? \App\Support\Dinheiro::paraCampo($ov->preco_centavos) : '', 'atributos' => ['inputmode' => 'decimal', 'placeholder' => 'padrão']])
                @endforeach
            </div>
        </div>
        <div class="form-section">
            <h2>Adicionais sugeridos</h2>
            <p class="hint">Itens que agregam à venda. Aparecem como sugestão no orçamento quando este item é incluído.</p>
            <fieldset class="adicionais-escolha"><legend class="sr-only">Adicionais</legend>
                @php($marcados = old('adicionais', $item->adicionais->pluck('id')->all()))
                <input type="hidden" name="adicionais[]" value="">
                @forelse ($outros as $o)
                    <label class="check"><input type="checkbox" name="adicionais[]" value="{{ $o->id }}" @checked(in_array($o->id, array_map('intval', (array) $marcados), true))> {{ $o->nome }} <span class="muted">({{ $o->categoria }}{{ $o->ativo ? '' : ', inativo' }})</span></label>
                @empty
                    <p class="muted">Cadastre outros itens para sugeri-los como adicionais.</p>
                @endforelse
            </fieldset>
            @error('adicionais')<span class="field-error">{{ $message }}</span>@enderror
        </div>
        <div class="form-footer"><button class="button primary" type="submit">Salvar alterações</button></div>
    </form>

    <section class="form-panel" style="margin-top:20px">
        <div class="form-section">
            <h2>Histórico de preço</h2>
            @if ($item->historico->isEmpty())
                <p class="muted">Sem alterações registradas.</p>
            @else
                <div class="table-wrap"><table class="data-table">
                    <thead><tr><th>Quando</th><th>Quem</th><th>Onde</th><th class="num">Preço</th><th class="num">Desc. máx.</th><th>Origem</th></tr></thead>
                    <tbody>
                    @foreach ($item->historico as $h)
                        <tr>
                            <td data-label="Quando">{{ \App\Support\Fuso::exibir($h->alterado_em) }}</td>
                            <td data-label="Quem">{{ $h->autor?->name }}</td>
                            <td data-label="Onde">{{ $h->unidade?->nome ?? 'Preço padrão' }}</td>
                            <td data-label="Preço" class="num">{{ $h->preco_anterior_centavos === null ? '—' : \App\Support\Dinheiro::formatar((int) $h->preco_anterior_centavos) }} → {{ $h->preco_novo_centavos === null ? '(padrão)' : \App\Support\Dinheiro::formatar((int) $h->preco_novo_centavos) }}</td>
                            <td data-label="Desc. máx." class="num">@if ($h->unidade_id === null){{ $h->desconto_max_anterior === null ? 'geral' : str_replace('.', ',', $h->desconto_max_anterior).'%' }} → {{ $h->desconto_max_novo === null ? 'geral' : str_replace('.', ',', $h->desconto_max_novo).'%' }}@else — @endif</td>
                            <td data-label="Origem">{{ $h->origem === 'importacao' ? 'Importação CSV' : 'Tela' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </div>
    </section>
@endsection
