@extends('layouts.app', ['titulo' => auth()->user()->visaoGlobal() ? 'Painel central' : 'Indicadores da loja'])
@section('conteudo')
    @php($f = fn ($c) => \App\Support\Dinheiro::formatar($c))
    <div class="page-head"><div><h1>{{ auth()->user()->visaoGlobal() ? 'Painel central' : 'Indicadores da loja' }}</h1>
        <p class="subtitle">{{ \Carbon\Carbon::parse($de)->format('d/m/Y') }} a {{ \Carbon\Carbon::parse($ate)->format('d/m/Y') }} · valores recebidos só de transações efetivas</p></div></div>
    <form method="GET" class="filters">
        @include('partials.campo', ['nome' => 'de', 'rotulo' => 'De', 'tipo' => 'date', 'valorAtual' => $de])
        @include('partials.campo', ['nome' => 'ate', 'rotulo' => 'Até', 'tipo' => 'date', 'valorAtual' => $ate])
        @if (\App\Models\Unidade::visiveisPara(auth()->user())->count() > 1)@include('partials.campo', ['nome' => 'unidade', 'rotulo' => 'Unidade', 'tipo' => 'select', 'opcoes' => ['' => 'Todas'] + \App\Models\Unidade::visiveisPara(auth()->user())->orderBy('nome')->pluck('nome', 'id')->all(), 'valorAtual' => request('unidade')])@endif
        <button class="button" type="submit">Atualizar</button>
    </form>
    <section class="summary" aria-label="Totais do período">
        <article><p>Entradas · saídas</p><strong>{{ $total['entradas'] ?? 0 }} · {{ $total['saidas'] ?? 0 }}</strong><small>Veículos no período</small></article>
        <article><p>Recebido (efetivo)</p><strong class="accent">{{ $f($total['recebido'] ?? 0) }}</strong><small>Pix {{ $f($total['recebido_pix'] ?? 0) }} · manual {{ $f($total['recebido_manual'] ?? 0) }}</small></article>
        <article><p>A receber (hoje)</p><strong>{{ $f($total['pendente'] ?? 0) }}</strong><small>Aprovado e ainda não pago</small></article>
    </section>
    @php($alertas = ($total['descontos_qtd'] ?? 0) + ($total['cancelamentos'] ?? 0) + ($total['liberacoes'] ?? 0) + ($total['falhas'] ?? 0) + ($total['divergencias_abertas'] ?? 0))
    <p class="notice">Pontos de atenção no período: {{ $total['descontos_qtd'] ?? 0 }} desconto(s), {{ $total['cancelamentos'] ?? 0 }} cancelamento(s), {{ $total['liberacoes'] ?? 0 }} liberação(ões) sem quitação, {{ $total['falhas'] ?? 0 }} falha(s) de integração/divergência de pagamento e {{ $total['divergencias_abertas'] ?? 0 }} divergência(s) de conferência em aberto. Alertas indicam necessidade de revisão, não provam irregularidade.</p>
    <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Unidade</th><th>Entradas</th><th>Saídas</th><th>Orçado</th><th>Aprovado</th><th>Recebido</th><th>A receber</th><th>Descontos</th><th>Cancel.</th><th>Sem quitação</th><th>Falhas</th><th>Diverg.</th></tr></thead>
        <tbody>
        @foreach ($unidades as $un)
            @php($l = $linhas[$un->id])
            <tr>
                <td data-label="Unidade"><a href="{{ route('painel', ['unidade' => $un->id]) }}">{{ $un->nome }}</a></td>
                <td data-label="Entradas">{{ $l['entradas'] }}</td><td data-label="Saídas">{{ $l['saidas'] }}</td>
                <td data-label="Orçado">{{ $f($l['orcado']) }}</td><td data-label="Aprovado">{{ $f($l['aprovado']) }} <span class="subline">{{ $l['aprovacoes'] }} aprov. · {{ $l['recusas'] }} recusas</span></td>
                <td data-label="Recebido">{{ $f($l['recebido']) }}@if ($l['devolvido'])<span class="subline">após devoluções de {{ $f($l['devolvido']) }}</span>@endif</td>
                <td data-label="A receber">{{ $f($l['pendente']) }}</td>
                <td data-label="Descontos">{{ $l['descontos_qtd'] }} · {{ $f($l['descontos']) }}</td>
                <td data-label="Cancelamentos">{{ $l['cancelamentos'] }}</td>
                <td data-label="Sem quitação">{{ $l['liberacoes'] }}@if ($l['liberado_sem_quitacao']) · {{ $f($l['liberado_sem_quitacao']) }}@endif</td>
                <td data-label="Falhas">{{ $l['falhas'] }}</td><td data-label="Divergências">{{ $l['divergencias_abertas'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    <section style="margin-top:24px"><div class="section-head"><h2>Ordens abertas por etapa (agora)</h2></div>
        <ul class="stage-list">@foreach (\App\Enums\StatusOrdem::abertas() as $s)<li><span>{{ $s->rotulo() }}</span><b>{{ $total['por_etapa'][$s->value] ?? 0 }}</b></li>@endforeach</ul>
    </section>
@endsection
