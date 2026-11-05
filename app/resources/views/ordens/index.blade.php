@extends('layouts.app', ['titulo' => 'Ordens'])
@section('conteudo')
    <div class="page-head">
        <div><h1>Ordens de serviço</h1><p class="subtitle">{{ $ordens->total() }} {{ $ordens->total() === 1 ? 'ordem' : 'ordens' }}</p></div>
        @can('registrar_entrada')<a class="button primary" href="{{ route('entradas.nova') }}"><span class="plus">＋</span> Nova entrada</a>@endcan
    </div>
    <form method="GET" class="filters">
        @include('partials.campo', ['nome' => 'busca', 'rotulo' => 'Número, nome, telefone ou placa', 'valorAtual' => request('busca')])
        @include('partials.campo', ['nome' => 'status', 'rotulo' => 'Situação', 'tipo' => 'select', 'opcoes' => ['' => 'Todas'] + collect(\App\Enums\StatusOrdem::cases())->mapWithKeys(fn ($s) => [$s->value => $s->rotulo()])->all(), 'valorAtual' => request('status')])
        @if ($unidades->count() > 1)
            @include('partials.campo', ['nome' => 'unidade', 'rotulo' => 'Unidade', 'tipo' => 'select', 'opcoes' => ['' => 'Todas'] + $unidades->pluck('nome', 'id')->all(), 'valorAtual' => request('unidade')])
        @endif
        @if ($filtroData)
            <input type="hidden" name="de" value="{{ $filtroData['de'] }}"><input type="hidden" name="ate" value="{{ $filtroData['ate'] }}"><input type="hidden" name="data" value="{{ $filtroData['tipo'] }}">
        @endif
        @if ($responsavelId)<input type="hidden" name="responsavel" value="{{ $responsavelId }}">@endif
        <button class="button" type="submit">Filtrar</button>
    </form>
    @if ($filtroData || $responsavelId)
        {{-- Filtro vindo do gráfico do painel: diz o que está aplicado e como sair dele. --}}
        <p class="filtro-ativo" role="status">
            <span>Filtro ativo: <strong>{{ $filtroData['texto'] ?? 'Ordens' }}</strong>@if ($responsavelId) · entrada registrada por {{ $responsavelNome ?? 'responsável selecionado' }}@endif</span>
            <a class="link" href="{{ route('ordens.index', request()->except('de', 'ate', 'data', 'responsavel', 'page')) }}">Limpar filtro de data</a>
        </p>
    @endif
    @include('ordens._lista', ['vazio' => $filtroData ? 'Nenhuma ordem neste período para o filtro ativo. Use "Limpar filtro de data" para ver todas.' : 'Nenhuma ordem para estes filtros.'])
    {{ $ordens->links() }}
@endsection
