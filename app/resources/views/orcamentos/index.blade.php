@extends('layouts.app', ['titulo' => 'Orçamentos'])
@section('conteudo')
    <div class="page-head"><div><h1>Orçamentos e versões</h1><p class="subtitle">Cada envio cria uma versão preservada. A aprovação vale apenas para a versão escolhida pelo cliente.</p></div></div>
    <form method="GET" class="filters">
        @include('partials.campo', ['nome' => 'status', 'rotulo' => 'Situação', 'tipo' => 'select', 'opcoes' => \App\Http\Controllers\OrcamentoController::FILTROS, 'valorAtual' => $status])
        @if ($unidades->count() > 1)
            @include('partials.campo', ['nome' => 'unidade', 'rotulo' => 'Unidade', 'tipo' => 'select', 'opcoes' => ['' => 'Todas'] + $unidades->pluck('nome', 'id')->all(), 'valorAtual' => request('unidade')])
        @endif
        <button class="button" type="submit">Filtrar</button>
    </form>
    @if ($orcamentos->isEmpty())
        <p class="muted">Nenhum orçamento nesta situação. Rascunhos ficam dentro de cada ordem até serem enviados.</p>
    @else
        <div class="table-wrap"><table class="data-table lista-orcamentos">
            <thead><tr><th>Ordem e versão</th>@if ($unidades->count() > 1)<th>Unidade</th>@endif<th>Enviado em</th><th class="num">Total</th><th>Situação</th><th><span class="sr-only">Ações</span></th></tr></thead>
            <tbody>
            @foreach ($orcamentos as $v)
                <tr>
                    <td data-label="Ordem"><strong>{{ $v->ordem->numero }} · v{{ $v->versao }}</strong> @if ($v->ordem->veiculo)<span class="plate">{{ $v->ordem->veiculo->placaFormatada() }}</span>@else<span class="item-avulso">{{ \Illuminate\Support\Str::limit($v->ordem->item_descricao, 40) }}</span>@endif</td>
                    @if ($unidades->count() > 1)<td data-label="Unidade">{{ $v->ordem->unidade->nome }}</td>@endif
                    <td data-label="Enviado em">{{ $v->enviado_em ? \App\Support\Fuso::exibir($v->enviado_em) : '—' }}</td>
                    <td data-label="Total" class="num">{{ \App\Support\Dinheiro::formatar((int) $v->total_centavos) }}</td>
                    <td data-label="Situação"><span class="tag orc-{{ $v->status }}">{{ $v->rotuloStatus() }}</span></td>
                    <td data-label="Ações"><a class="button" href="{{ route('ordens.show', [$v->ordem, 'aba' => 'orcamento']) }}">Abrir orçamento</a></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $orcamentos->links() }}
    @endif
@endsection
