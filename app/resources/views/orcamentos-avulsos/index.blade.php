@extends('layouts.app', ['titulo' => 'Orçamentos sem entrada'])
@section('conteudo')
    <div class="page-head"><div><h1>Orçamentos sem entrada</h1>
        <p class="subtitle">Orçamento para quem ainda não trouxe o carro. Se o cliente aprovar, a OS da chegada fica com o mesmo número e os mesmos itens.</p></div>
        <div class="page-acoes"><a class="button primary" href="{{ route('orcamentos-avulsos.create') }}">Novo orçamento sem entrada</a></div></div>
    <form method="GET" class="filters">
        @include('partials.campo', ['nome' => 'status', 'rotulo' => 'Situação', 'tipo' => 'select', 'valorAtual' => $status,
            'opcoes' => ['abertos' => 'Em aberto (rascunho, enviado, aprovado)'] + \App\Models\OrcamentoAvulso::SITUACOES + ['todos' => 'Todos']])
        <button class="button" type="submit">Filtrar</button>
    </form>
    @if ($orcamentos->isEmpty())
        <p class="muted">Nenhum orçamento nesta situação.</p>
    @else
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Número</th><th>Cliente e veículo</th><th>Unidade</th><th class="num">Total</th><th>Situação</th><th>Validade</th></tr></thead>
            <tbody>
            @foreach ($orcamentos as $o)
                <tr>
                    <td data-label="Número"><a href="{{ route('orcamentos-avulsos.show', $o) }}"><strong>{{ $o->numero }}</strong></a></td>
                    <td data-label="Cliente">{{ $o->cliente->nome }}<br><small class="muted">{{ $o->veiculoTexto() }}</small></td>
                    <td data-label="Unidade">{{ $o->unidade->nome }}</td>
                    <td data-label="Total" class="num">{{ \App\Support\Dinheiro::formatar($o->total_centavos) }}</td>
                    <td data-label="Situação"><span class="tag">{{ $o->rotuloSituacao() }}</span>@if ($o->ordem) <a href="{{ route('ordens.show', $o->ordem) }}">ver OS</a>@endif</td>
                    <td data-label="Validade">{{ $o->validade->format('d/m/Y') }}@if (in_array($o->status, ['rascunho', 'enviado'], true) && $o->vencido()) <span class="tag off">vencido</span>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @include('partials.paginacao', ['paginator' => $orcamentos])
    @endif
@endsection
