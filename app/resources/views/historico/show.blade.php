@extends('layouts.app', ['titulo' => 'OS antiga '.$os['CODIGO']])
@section('conteudo')
    @php($situacaoOs = fn (array $o) => ($o['CANCELADA'] ?? '') === 'S' ? 'Cancelada' : (($o['STATUS'] ?? '') === 'F' ? 'Finalizada' : 'Aberta'))
    @php($dinheiro = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.'))
    @php($dataOs = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d/m/Y') : '—')
    <div class="page-head">
        <div><h1>OS {{ $os['CODIGO'] }} <span @class(['tag', 'off' => $situacaoOs($os) === 'Cancelada'])>{{ $situacaoOs($os) }}</span></h1>
            <p class="subtitle">Sistema antigo · só consulta · <a href="{{ route('historico.index') }}">voltar à lista</a></p></div>
        <div class="page-acoes"><a class="button primary" href="{{ route('historico.imprimir', request()->route('id')) }}" target="_blank" rel="noopener">Imprimir (versão simples)</a></div>
    </div>
    <section class="form-panel"><div class="form-section legado">
        <dl class="legado-campos">
            <dt>Cliente</dt><dd>@if ($cliente)<a href="{{ route('clientes.show', $cliente) }}">{{ $cliente->nome }}</a>@else — @endif</dd>
            <dt>Veículo</dt><dd>@if ($veiculo){{ $veiculo->placaFormatada() }} {{ $veiculo->descricao() }}@else — @endif</dd>
            @if (! empty($os['KM']))<dt>KM</dt><dd>{{ $os['KM'] }}</dd>@endif
            <dt>Entrada / saída</dt><dd>{{ $dataOs($os['DATAIN'] ?? null) }} / {{ $dataOs($os['DATAOUT'] ?? null) }}</dd>
            @can('ver_valores')
                <dt>Serviços / peças</dt><dd>{{ $dinheiro($os['VLSERVICO'] ?? 0) }} / {{ $dinheiro($os['VLPECA'] ?? 0) }}</dd>
                <dt>Total</dt><dd><strong>{{ $dinheiro($os['TOTAL'] ?? 0) }}</strong></dd>
                <dt>Pago</dt><dd>{{ ($os['PAG'] ?? '') === 'S' ? 'Sim'.(! empty($os['DATAPAG']) ? ' em '.$dataOs($os['DATAPAG']) : '') : 'Não' }}</dd>
            @endcan
            @foreach (['DESCRICAO' => 'Descrição', 'OBS' => 'Observações'] as $k => $rot)
                @if (! empty($os[$k]))<dt>{{ $rot }}</dt><dd style="white-space:pre-line">{{ $os[$k] }}</dd>@endif
            @endforeach
        </dl>
        <h2 style="margin-top:20px">Serviços e peças</h2>
        @if ($itens->isEmpty())
            <p class="muted">Nenhum item registrado nesta OS.</p>
        @else
            <ul class="historico-itens">
                @foreach ($itens as $i)
                    <li>{{ $i['tipo'] }}: {{ trim($i['DESCRICAO'] ?? '') ?: ($i['_servico'] ?? $i['_produto'] ?? 'item '.($i['CODIGO'] ?? '')) }}
                        · {{ rtrim(rtrim((string) ($i['QTD'] ?? '1'), '0'), '.') ?: '1' }}×
                        @can('ver_valores') · {{ $dinheiro($i['PRECO'] ?? 0) }}@endcan</li>
                @endforeach
            </ul>
        @endif
        <details style="margin-top:16px"><summary>Ver todos os campos desta OS</summary>
            <dl class="legado-campos">@foreach ($os as $campo => $valor)<dt>{{ $campo }}</dt><dd>{{ $valor }}</dd>@endforeach</dl>
        </details>
        @foreach ($outros as $tabela => $linhas)
            <details><summary>{{ $tabela }} ({{ $linhas->count() }})</summary>
                @foreach ($linhas as $l)<dl class="legado-campos" style="border-top:1px solid var(--t-borda,#e4e8ef);padding-top:6px">@foreach ($l as $campo => $valor)<dt>{{ $campo }}</dt><dd>{{ \Illuminate\Support\Str::limit((string) $valor, 300) }}</dd>@endforeach</dl>@endforeach
            </details>
        @endforeach
    </div></section>
@endsection
