@extends('layouts.app', ['titulo' => 'Conferência diária'])
@section('conteudo')
    @php($u = auth()->user())
    <div class="page-head"><div><h1>Conferência diária</h1><p class="subtitle">Confira no pátio cada veículo com ordem aberta e anote veículos sem ordem. Divergência indica revisão, não prova irregularidade.</p></div></div>
    @if ($unidades->count() > 1)
        <form method="GET" class="filters">@include('partials.campo', ['nome' => 'unidade', 'rotulo' => 'Unidade', 'tipo' => 'select', 'opcoes' => $unidades->pluck('nome', 'id')->all(), 'valorAtual' => $unidadeId])<button class="button" type="submit">Ver</button></form>
    @endif
    @can('conferir')
    <form method="POST" action="{{ route('conferencia.store') }}" class="form-panel" style="margin-bottom:24px" data-conferencia>
        @csrf <input type="hidden" name="unidade" value="{{ $unidadeId }}">
        <section class="form-section">
            <h2>Veículos que deveriam estar na oficina ({{ $esperadas->count() }})</h2>
            @error('placas')<p class="alert erro">{{ $message }}</p>@enderror
            @if ($esperadas->isEmpty())<p class="muted">Nenhuma ordem aberta nesta unidade.</p>@endif
            @if ($esperadas->isNotEmpty())
                <div class="conf-barra">
                    <div class="field"><label for="conf-busca">Buscar placa</label><input id="conf-busca" type="search" autocomplete="off" autocapitalize="characters" enterkeyhint="search" data-conf-busca></div>
                    <p class="conf-progresso" aria-live="polite" data-conf-progresso>0 de {{ $esperadas->count() }} conferidos · {{ $esperadas->count() }} restantes</p>
                </div>
                <p class="hint">Marque cada veículo. Os que ficarem sem marcação serão registrados como ausentes.</p>
            @endif
            <div class="conf-lista">
                @foreach ($esperadas as $o)
                    <fieldset class="conf-item" data-placa="{{ $o->veiculo?->placa ?? \App\Support\Normaliza::placa($o->numero.' '.$o->item_descricao) }}">
                        @if ($o->veiculo)
                            <legend><span class="plate">{{ $o->veiculo->placaFormatada() }}</span> {{ $o->numero }} · {{ $o->status->rotulo() }}</legend>
                            <p class="hint">{{ $o->veiculo->modelo }} · {{ $o->cliente->nome }}</p>
                        @else
                            <legend><span class="item-avulso">Sem veículo</span> {{ $o->numero }} · {{ $o->status->rotulo() }}</legend>
                            <p class="hint">Item: {{ $o->item_descricao }} · {{ $o->cliente->nome }}</p>
                        @endif
                        <div class="conf-opcoes">
                            <label><input type="radio" name="marcacao[{{ $o->id }}]" value="presente"><span>Presente</span></label>
                            <label><input type="radio" name="marcacao[{{ $o->id }}]" value="ausente"><span>Ausente</span></label>
                        </div>
                    </fieldset>
                @endforeach
            </div>
        </section>
        <section class="form-section">
            <h2>Veículos no pátio sem ordem</h2>
            <div class="field full"><label for="placas">Placas encontradas que não aparecem acima (separe por espaço ou linha)</label>
                <textarea id="placas" name="placas" rows="2" aria-describedby="placas-ajuda">{{ old('placas') }}</textarea>
                <p class="hint" id="placas-ajuda">Exemplo: ABC1D23 XYZ9876</p></div>
            @include('partials.campo', ['nome' => 'observacao', 'rotulo' => 'Observação (opcional)'])
        </section>
        <div class="form-footer"><button class="button primary" type="submit">Registrar conferência</button></div>
    </form>
    @endcan

    <section style="margin-bottom:24px">
        <div class="section-head"><h2>Divergências aguardando revisão</h2><small>{{ $abertas->count() }}</small></div>
        @forelse ($abertas as $d)
            <article class="versao">
                <header><strong>@if ($d->placa)<span class="plate">{{ \App\Support\Normaliza::placaExibicao($d->placa) }}</span>@else<span class="item-avulso">{{ $d->ordem?->numero }} · {{ \Illuminate\Support\Str::limit($d->ordem?->item_descricao, 40) }}</span>@endif · {{ $d->rotulo() }}</strong><span class="tag extra">Revisão: {{ $d->revisor->name }}</span></header>
                <p class="hint">Detectada em {{ \App\Support\Fuso::exibir($d->created_at) }}@if ($d->ordem) · ordem <a href="{{ route('ordens.show', $d->ordem) }}">{{ $d->ordem->numero }}</a>@endif</p>
                @can('resolver_divergencias')
                    <form method="POST" action="{{ route('conferencia.resolver', $d) }}" class="inline-form">@csrf
                        @include('partials.campo', ['nome' => 'resolucao', 'rotulo' => 'O que foi verificado e decidido', 'id' => 'resolucao-'.$d->id])
                        <button class="button" type="submit">Registrar resolução</button></form>
                @endcan
            </article>
        @empty
            <p class="muted">Nenhuma divergência em aberto.</p>
        @endforelse
    </section>
    @if ($resolvidas->isNotEmpty())
        <section><div class="section-head"><h2>Resolvidas recentemente</h2></div>
            @foreach ($resolvidas as $d)<p>@if ($d->placa)<span class="plate">{{ \App\Support\Normaliza::placaExibicao($d->placa) }}</span>@else<span class="item-avulso">{{ $d->ordem?->numero }}</span>@endif {{ $d->rotulo() }} — {{ $d->resolucao }} <span class="subline">{{ $d->resolvidoPor->name }} · {{ \App\Support\Fuso::exibir($d->resolvido_em) }}</span></p>@endforeach
        </section>
    @endif
    <p class="hint">Últimas conferências: @forelse ($ultimas as $c){{ \Carbon\Carbon::parse($c->data)->format('d/m') }} ({{ $c->presentes }}/{{ $c->esperados }})@if(! $loop->last), @endif @empty nenhuma @endforelse</p>
@endsection
