@extends('layouts.app', ['titulo' => 'Catálogo'])
@section('conteudo')
    <div class="page-head"><div><h1>Catálogo de serviços e produtos</h1><p class="subtitle">Preço de tabela e desconto máximo sem aprovação. Descontos acima do limite precisam de aprovação interna antes de concluir o orçamento. Itens sem limite próprio seguem a regra geral de {{ str_replace('.', ',', rtrim(rtrim(\App\Services\AprovacoesInternas::regraGeral(), '0'), '.')) }}%.</p></div></div>

    @if ($relatorio)
        <section class="versao relatorio-importacao" aria-labelledby="relatorio-titulo">
            <header><strong id="relatorio-titulo">Resultado da importação</strong><span class="tag">{{ $relatorio['criados'] }} novos · {{ $relatorio['atualizados'] }} atualizados · {{ $relatorio['inalterados'] }} sem mudança</span></header>
            @if ($relatorio['recusadas'])
                <p><strong>{{ count($relatorio['recusadas']) }} linha(s) recusada(s)</strong> — corrija no arquivo e importe de novo (linhas válidas já foram gravadas):</p>
                <div class="table-wrap"><table class="data-table">
                    <thead><tr><th>Linha</th><th>Conteúdo</th><th>Motivo</th></tr></thead>
                    <tbody>
                    @foreach ($relatorio['recusadas'] as $r)
                        <tr><td data-label="Linha">{{ $r['linha'] }}</td><td data-label="Conteúdo"><code>{{ $r['conteudo'] }}</code></td><td data-label="Motivo">{{ implode(' ', $r['motivos']) }}</td></tr>
                    @endforeach
                    </tbody>
                </table></div>
            @else
                <p class="muted">Nenhuma linha recusada.</p>
            @endif
        </section>
    @endif

    <form method="GET" class="filters">
        @include('partials.campo', ['nome' => 'busca', 'rotulo' => 'Buscar por nome', 'valorAtual' => request('busca')])
        @include('partials.campo', ['nome' => 'categoria', 'rotulo' => 'Categoria', 'tipo' => 'select', 'opcoes' => ['' => 'Todas'] + $categorias->combine($categorias)->all(), 'valorAtual' => request('categoria')])
        @include('partials.campo', ['nome' => 'situacao', 'rotulo' => 'Situação', 'tipo' => 'select', 'opcoes' => ['ativos' => 'Ativos', 'inativos' => 'Inativos', 'todos' => 'Todos'], 'valorAtual' => $situacao])
        <button class="button" type="submit">Filtrar</button>
    </form>

    @if ($itens->isEmpty())
        <p class="muted">Nenhum item nesta busca. Inclua abaixo ou importe a tabela da empresa (CSV).</p>
    @else
        <div class="table-wrap"><table class="data-table lista-catalogo">
            <thead><tr><th>Item</th><th>Categoria</th><th>Unidade</th><th class="num">Preço padrão</th><th class="num">Desc. máx.</th><th>Situação</th><th><span class="sr-only">Ações</span></th></tr></thead>
            <tbody>
            @foreach ($itens as $c)
                <tr>
                    <td data-label="Item"><strong>{{ $c->nome }}</strong>@if ($c->adicionais_count)<span class="muted"> · {{ $c->adicionais_count }} adicional(is)</span>@endif</td>
                    <td data-label="Categoria">{{ $c->categoria }}</td>
                    <td data-label="Unidade">{{ $c->rotuloUnidade() }}</td>
                    <td data-label="Preço padrão" class="num">{{ \App\Support\Dinheiro::formatar($c->preco_centavos) }}</td>
                    <td data-label="Desc. máx." class="num">{{ $c->desconto_max_percentual === null ? 'regra geral' : str_replace('.', ',', rtrim(rtrim($c->desconto_max_percentual, '0'), '.')).'%' }}</td>
                    <td data-label="Situação"><span @class(['tag', 'off' => ! $c->ativo])>{{ $c->ativo ? 'Ativo' : 'Inativo' }}</span></td>
                    <td data-label="Ações"><a class="button" href="{{ route('catalogo.edit', $c) }}">Editar</a></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $itens->links() }}
    @endif

    <div class="catalogo-paineis">
        <section class="form-panel">
            <div class="form-section">
                <h2>Novo item</h2>
                <form method="POST" action="{{ route('catalogo.store') }}">
                    @csrf
                    <div class="fields">
                        @include('partials.campo', ['nome' => 'nome', 'rotulo' => 'Nome (ex.: Reforma geral aro 17)', 'obrigatorio' => true, 'atributos' => ['maxlength' => 150]])
                        @include('partials.campo', ['nome' => 'categoria', 'id' => 'categoria-novo', 'rotulo' => 'Categoria (ex.: Rodas, Baterias, Mecânica)', 'obrigatorio' => true, 'atributos' => ['maxlength' => 60, 'list' => 'categorias-lista']])
                        @include('partials.campo', ['nome' => 'unidade_venda', 'rotulo' => 'Unidade de venda', 'tipo' => 'select', 'opcoes' => \App\Models\CatalogoItem::UNIDADES_VENDA])
                        @include('partials.campo', ['nome' => 'preco', 'rotulo' => 'Preço padrão (R$)', 'obrigatorio' => true, 'atributos' => ['inputmode' => 'decimal', 'placeholder' => '0,00']])
                        @if ($podeLimite)
                            @include('partials.campo', ['nome' => 'desconto_max', 'rotulo' => 'Desconto máximo sem aprovação (%)', 'ajuda' => 'Em branco: regra geral.', 'atributos' => ['inputmode' => 'decimal', 'placeholder' => 'regra geral']])
                        @endif
                    </div>
                    <datalist id="categorias-lista">@foreach ($categorias as $cat)<option value="{{ $cat }}">@endforeach</datalist>
                    @unless ($podeLimite)<p class="hint">O desconto máximo é definido pelo proprietário (ou por quem aprova descontos). Sem limite próprio, vale a regra geral.</p>@endunless
                    <button class="button primary" type="submit">Incluir no catálogo</button>
                </form>
            </div>
        </section>
        <section class="form-panel">
            <div class="form-section">
                <h2>Importar tabela (CSV)</h2>
                <p class="hint">Uma linha por item, separada por ponto e vírgula: <code>nome;categoria;unidade;preço;desconto_max</code>. Cabeçalho opcional. Unidade: roda, jogo, unidade ou hora. Nome já cadastrado é atualizado; desconto em branco mantém o limite atual.</p>
                <p class="hint">Exemplo: <code>Reforma geral aro 17;Rodas;roda;180,00;10</code></p>
                <form method="POST" action="{{ route('catalogo.importar') }}" enctype="multipart/form-data">
                    @csrf
                    @include('partials.campo', ['nome' => 'arquivo', 'rotulo' => 'Arquivo CSV (até 1 MB)', 'tipo' => 'file', 'obrigatorio' => true, 'atributos' => ['accept' => '.csv,text/csv,text/plain']])
                    <button class="button" type="submit">Importar e ver relatório</button>
                </form>
            </div>
        </section>
    </div>
@endsection
