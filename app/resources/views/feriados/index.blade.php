@extends('layouts.app', ['titulo' => 'Feriados da unidade'])
@section('conteudo')
    @php($nomeUnidade = $unidades->firstWhere('id', $unidadeId)?->nome)
    <div class="page-head"><div><h1>Feriados da unidade</h1>
        <p class="subtitle">{{ $nomeUnidade }} · feriados estaduais e municipais somados aos nacionais no cálculo da data de entrega em dias úteis.</p></div></div>
    @if ($unidades->count() > 1)
        <form method="GET" class="filters" aria-label="Escolher unidade">
            @include('partials.campo', ['nome' => 'unidade', 'rotulo' => 'Unidade', 'tipo' => 'select', 'opcoes' => $unidades->pluck('nome', 'id')->all(), 'valorAtual' => $unidadeId])
            <button class="button" type="submit">Ver</button>
        </form>
    @endif

    <div class="feriados-grade">
        <section class="form-panel">
            <h2>Novo feriado local</h2>
            <form method="POST" action="{{ route('feriados.store') }}" class="inline-form feriado-form" novalidate>
                @csrf
                <input type="hidden" name="unidade_id" value="{{ $unidadeId }}">
                @include('partials.campo', ['nome' => 'data', 'rotulo' => 'Data', 'tipo' => 'date', 'obrigatorio' => true])
                @include('partials.campo', ['nome' => 'descricao', 'rotulo' => 'Descrição (ex.: Aniversário de Goiânia)', 'obrigatorio' => true])
                <button class="button primary" type="submit">Cadastrar feriado</button>
            </form>
            <p class="hint">Vale para prazos definidos a partir de agora. Prazos já definidos não são recalculados.</p>
        </section>

        <section class="cartao">
            <header class="cartao-topo"><h2>Feriados locais de {{ $nomeUnidade }}</h2><small>{{ $feriados->count() }}</small></header>
            @if ($feriados->isEmpty())
                <p class="muted">Nenhum feriado local cadastrado. Só os nacionais são considerados.</p>
            @else
                <ul class="feriados-lista">
                    @foreach ($feriados as $fer)
                        <li>
                            <span><strong>{{ $fer->data->format('d/m/Y') }}</strong> <small>{{ \Illuminate\Support\Str::ucfirst($fer->data->locale('pt_BR')->isoFormat('dddd')) }}</small></span>
                            <span>{{ $fer->descricao }}</span>
                            <form method="POST" action="{{ route('feriados.destroy', $fer) }}">
                                @csrf @method('DELETE')
                                <button class="button" type="submit" aria-label="Remover feriado de {{ $fer->data->format('d/m/Y') }}">Remover</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <details class="feriados-nacionais">
        <summary>Feriados nacionais considerados ({{ count($nacionais) }})</summary>
        <p class="hint">{{ collect($nacionais)->map(fn ($d) => \Carbon\Carbon::parse($d)->format('d/m/Y'))->join(' · ') }}</p>
    </details>
@endsection
