@extends('layouts.app', ['titulo' => 'Clientes'])
@section('conteudo')
    <div class="page-head">
        <div><h1>Clientes</h1><p class="subtitle">{{ $busca === '' ? 'Atendidos nas suas unidades, do mais recente para o mais antigo.' : 'Resultado da busca no cadastro da rede.' }} Clientes são criados na entrada; o telefone evita duplicidade.</p></div>
        @can('registrar_entrada')<div class="page-acoes"><a class="button primary" href="{{ route('entradas.nova') }}"><span class="plus">＋</span> Nova entrada</a></div>@endcan
    </div>
    <form method="GET" class="barra-busca" role="search">
        <label class="barra-busca-rotulo" for="busca">Buscar cliente</label>
        @include('partials.icone', ['i' => 'busca'])
        <input id="busca" name="busca" type="search" value="{{ $busca }}" placeholder="Buscar por nome, telefone ou placa" autocomplete="off" enterkeyhint="search">
        @if ($busca !== '')<a class="limpar" href="{{ route('clientes.index') }}">Limpar</a>@endif
        <button class="button primary" type="submit">Buscar</button>
    </form>
    <p class="contagem">{{ $clientes->total() }} {{ $clientes->total() === 1 ? 'cliente' : 'clientes' }}@if ($busca !== '') para “{{ $busca }}”@endif</p>
    @if ($clientes->isEmpty())
        <div class="vazio-cartao">@include('partials.icone', ['i' => 'clientes'])<p>{{ $busca === '' ? 'Nenhum cliente atendido nas suas unidades ainda.' : 'Nenhum cliente encontrado. Confira a grafia ou busque pela placa.' }}</p>
            @can('registrar_entrada')<a class="button" href="{{ route('entradas.nova') }}">Registrar nova entrada</a>@endcan</div>
    @else
        <ul class="lista-fichas">
            @foreach ($clientes as $c)
                <li><a class="ficha" href="{{ route('clientes.show', $c) }}">
                    <span class="ficha-avatar tom-{{ $c->id % 6 }}" aria-hidden="true">{{ $c->iniciais() }}</span>
                    <span class="ficha-corpo">
                        <strong>{{ $c->nome }}</strong>
                        <small>{{ $c->telefoneFormatado() }}@if ($c->email) · {{ $c->email }}@endif</small>
                        <span class="ficha-placas">@forelse ($c->veiculos->take(3) as $v)<span class="chip-placa">{{ $v->placaFormatada() }}</span>@empty<span class="muted">Sem veículo</span>@endforelse
                            @if ($c->veiculos->count() > 3)<span class="muted">+{{ $c->veiculos->count() - 3 }}</span>@endif</span>
                    </span>
                    <span class="ficha-meta"><b>{{ $c->ordens_count }}</b><small>{{ $c->ordens_count === 1 ? 'atendimento' : 'atendimentos' }}</small>
                        @if ($c->ordens_max_entrada_em)<small>última {{ \App\Support\Fuso::exibir(\Carbon\CarbonImmutable::parse($c->ordens_max_entrada_em), 'd/m/Y') }}</small>@endif</span>
                    <span class="ficha-seta" aria-hidden="true">›</span>
                </a></li>
            @endforeach
        </ul>
        @include('partials.paginacao', ['paginator' => $clientes])
    @endif
@endsection
