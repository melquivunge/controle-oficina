@extends('layouts.app', ['titulo' => 'Meu painel'])
@section('conteudo')
    <div class="page-head">
        <div><h1>Olá, {{ \Illuminate\Support\Str::before(auth()->user()->name.' ', ' ') }}</h1><p class="subtitle">Seus serviços e prazos de entrega · {{ \App\Support\Fuso::exibir(now(), 'd/m/Y') }}</p></div>
        <div class="page-acoes"><a class="button primary" href="{{ route('fila') }}">Abrir minha fila</a></div>
    </div>
    <section class="kpis" aria-label="Resumo dos seus serviços">
        <article class="kpi"><h2>Atrasados</h2><div class="kpi-corpo"><strong @class(['texto-alerta' => $atrasadas->isNotEmpty()])>{{ $atrasadas->count() }}</strong></div><small>Prazo de entrega vencido</small></article>
        <article class="kpi"><h2>Entregar hoje</h2><div class="kpi-corpo"><strong>{{ $hoje->count() }}</strong></div><small>Com entrega prevista para hoje</small></article>
        <article class="kpi"><h2>Em execução</h2><div class="kpi-corpo"><strong>{{ $emExecucao }}</strong></div><small>{{ $aguardando }} aguardando início</small></article>
        <article class="kpi"><h2>Prontos na semana</h2><div class="kpi-corpo"><strong>{{ $prontasSemana }}</strong></div><small>Marcados por você nos últimos 7 dias</small></article>
    </section>
    @if ($livres)
        <p class="notice">{{ $livres }} veículo(s) da unidade aguardando diagnóstico sem responsável. <a class="link" href="{{ route('fila') }}">Ver na fila →</a></p>
    @endif
    <section class="cartao">
        <header class="cartao-topo"><div><h2>Próximas entregas</h2><p class="subtitle">Seus serviços abertos, do prazo mais próximo ao mais distante</p></div></header>
        @if ($minhas->isEmpty())
            <div class="vazio-cartao"><p>Nenhum serviço atribuído a você agora.</p><a class="button" href="{{ route('fila') }}">Ver fila da unidade</a></div>
        @else
            <ul class="lista-servicos">
                @foreach ($minhas as $o)
                    <li><a href="{{ route('ordens.show', $o) }}" @class(['servico', 'atrasado' => $o->entregaAtrasada()])>
                        <span class="servico-prazo"><b>{{ $o->entrega_prevista_em?->format('d/m') ?? '—' }}</b><small>{{ $o->entregaAtrasada() ? 'atrasado' : ($o->entrega_prevista_em ? 'entrega' : 'sem prazo') }}</small></span>
                        <span class="servico-corpo"><strong>{{ $o->numero }} · {{ $o->veiculo?->modelo ?? 'Peças avulsas' }}</strong>
                            <small>@if ($o->veiculo)<span class="chip-placa">{{ $o->veiculo->placaFormatada() }}</span> @endif{{ \Illuminate\Support\Str::limit($o->motivo, 70) }}</small></span>
                        @include('partials.status', ['status' => $o->status])
                    </a></li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
