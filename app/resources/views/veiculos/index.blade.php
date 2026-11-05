@extends('layouts.app', ['titulo' => 'Veículos'])
@section('conteudo')
    <div class="page-head"><div><h1>Veículos</h1><p class="subtitle">Veículos atendidos nas suas unidades, com as ordens mais recentes.</p></div></div>
    <form method="GET" class="filters busca-destaque" role="search">
        @include('partials.campo', ['nome' => 'placa', 'rotulo' => 'Placa (completa ou parte dela)', 'valorAtual' => $placa ? \App\Support\Normaliza::placaExibicao($placa) : '', 'atributos' => ['autocapitalize' => 'characters', 'autocomplete' => 'off', 'enterkeyhint' => 'search', 'inputmode' => 'text']])
        <button class="button primary" type="submit">Buscar</button>
        @if ($placa !== '')<a class="button" href="{{ route('veiculos.index') }}">Limpar</a>@endif
    </form>
    @if ($veiculos->isEmpty())
        <p class="muted">@if ($placa !== '')Nenhum veículo com placa contendo “{{ $placa }}” nas suas unidades. Confira a digitação ou registre uma <a href="{{ route('entradas.nova') }}">nova entrada</a>.@else Nenhum veículo atendido ainda. Comece por uma <a href="{{ route('entradas.nova') }}">nova entrada</a>.@endif</p>
    @else
        <p class="hint resultado-contagem">{{ $veiculos->total() }} veículo(s)</p>
        <ul class="cartoes-veiculo">
            @foreach ($veiculos as $v)
                <li class="cartao-veiculo">
                    <header>
                        <span class="plate placa-grande">{{ $v->placaFormatada() }}</span>
                        <small>{{ $v->ordens_count }} OS</small>
                    </header>
                    <p class="cartao-veiculo-dados">{{ collect([$v->modelo, $v->ano, $v->cor])->filter()->implode(' ') ?: 'Modelo não informado' }} · <a href="{{ route('clientes.show', $v->cliente_id) }}">{{ $v->cliente->nome }}</a></p>
                    <ul class="ordens-recentes" aria-label="Últimas ordens de {{ $v->placaFormatada() }}">
                        @foreach ($ordens[$v->id] ?? [] as $o)
                            <li>
                                <a href="{{ route('ordens.show', $o) }}">{{ $o->numero }}</a>
                                @include('partials.status', ['status' => $o->status])
                                <time datetime="{{ $o->entrada_em?->toIso8601String() }}">{{ \App\Support\Fuso::exibir($o->entrada_em, 'd/m/Y') }}</time>
                            </li>
                        @endforeach
                    </ul>
                </li>
            @endforeach
        </ul>
        {{ $veiculos->links() }}
    @endif
@endsection
