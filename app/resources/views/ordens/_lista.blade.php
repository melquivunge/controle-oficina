@if ($ordens->isEmpty())
    <p class="muted">{{ $vazio ?? 'Nenhuma ordem encontrada.' }}</p>
@else
<div class="queue">
    <div class="queue-head"><span>Cliente e veículo</span><span>Placa e entrada</span><span>Situação e entrega</span><span></span></div>
    @foreach ($ordens as $o)
        @php($atrasada = $o->entregaAtrasada())
        <div @class(['queue-row', 'atrasada' => $atrasada])>
            <div><strong>{{ $o->cliente->nome }}</strong><span class="subline">{{ $o->numero }}{{ $o->veiculo?->modelo ? ' · '.$o->veiculo->modelo : '' }}@if(auth()->user()->visaoGlobal() || count(auth()->user()->unidadeIds()) > 1) · {{ $o->unidade->nome }}@endif</span></div>
            <div>@if ($o->veiculo)<span class="plate">{{ $o->veiculo->placaFormatada() }}</span>@else<span class="item-avulso" title="Sem veículo (peças avulsas)">{{ \Illuminate\Support\Str::limit($o->item_descricao, 40) }}</span>@endif<span class="subline">Entrada {{ \App\Support\Fuso::exibir($o->entrada_em) }}{{ $o->contingencia ? ' · contingência' : '' }}</span></div>
            <div>@include('partials.status', ['status' => $o->status])
                @if ($o->entrega_prevista_em)<span @class(['subline', 'prazo-entrega', 'atrasada' => $atrasada])>@if ($atrasada)<b>Atrasada</b> · @endif Entrega prevista {{ $o->entrega_prevista_em->format('d/m') }}</span>@endif</div>
            <a class="row-link" href="{{ route('ordens.show', $o) }}" aria-label="Ver ordem {{ $o->numero }} de {{ $o->cliente->nome }}">Ver ordem</a>
        </div>
    @endforeach
</div>
@endif
