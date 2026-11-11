@extends('layouts.app', ['titulo' => 'OS do sistema antigo'])
@section('conteudo')
    @php($situacaoOs = fn (array $o) => ($o['CANCELADA'] ?? '') === 'S' ? 'Cancelada' : (($o['STATUS'] ?? '') === 'F' ? 'Finalizada' : 'Aberta'))
    @php($dinheiro = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.'))
    @php($dataOs = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d/m/Y') : '—')
    <div class="page-head">
        <div><h1>OS do sistema antigo</h1><p class="subtitle">{{ number_format($total, 0, ',', '.') }} OS importadas, só para consulta. Não entram no painel, no caixa nem nos relatórios. As OS novas ficam em <a href="{{ route('ordens.index') }}">Ordens de serviço</a>.</p></div>
    </div>
    <form method="GET" class="filters">
        @include('partials.campo', ['nome' => 'busca', 'rotulo' => 'Número, cliente, telefone ou placa', 'valorAtual' => $f['busca'] ?? ''])
        @include('partials.campo', ['nome' => 'situacao', 'rotulo' => 'Situação', 'tipo' => 'select', 'opcoes' => ['' => 'Todas', 'importar' => 'Para importar (abertas ou com saldo)', 'aberta' => 'Aberta', 'finalizada' => 'Finalizada', 'cancelada' => 'Cancelada'], 'valorAtual' => $f['situacao'] ?? ''])
        @include('partials.campo', ['nome' => 'de', 'rotulo' => 'Entrada de', 'tipo' => 'date', 'valorAtual' => $f['de'] ?? ''])
        @include('partials.campo', ['nome' => 'ate', 'rotulo' => 'até', 'tipo' => 'date', 'valorAtual' => $f['ate'] ?? ''])
        <button class="button" type="submit">Filtrar</button>
    </form>
    <p class="contagem">{{ $lista->total() }} {{ $lista->total() === 1 ? 'OS encontrada' : 'OS encontradas' }}</p>
    @if ($lista->isEmpty())
        <div class="vazio-cartao"><p>Nenhuma OS antiga para estes filtros.</p></div>
    @else
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>OS</th><th>Entrada</th><th>Cliente</th><th>Veículo</th>@can('ver_valores')<th>Total</th>@endcan<th>Situação</th></tr></thead>
            <tbody>
            @foreach ($lista as $l)
                @php($o = json_decode($l->dados, true))
                <tr>
                    <td data-label="OS"><a class="link" href="{{ route('historico.show', $l->id) }}">{{ $l->codigo }}</a></td>
                    <td data-label="Entrada">{{ $dataOs($o['DATAIN'] ?? null) }}</td>
                    <td data-label="Cliente">@if ($l->cliente_id)<a href="{{ route('clientes.show', $l->cliente_id) }}">{{ $l->nome }}</a>@else — @endif</td>
                    <td data-label="Veículo">@if ($l->placa)<span class="chip-placa">{{ \App\Support\Normaliza::placaExibicao($l->placa) }}</span> {{ trim($l->marca.' '.$l->modelo) }}@else — @endif</td>
                    @can('ver_valores')<td data-label="Total">{{ $dinheiro($o['TOTAL'] ?? 0) }}</td>@endcan
                    <td data-label="Situação"><span @class(['tag', 'off' => $situacaoOs($o) === 'Cancelada'])>{{ $situacaoOs($o) }}</span>@if ($l->ordem_id) <span class="tag">Importada</span>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $lista->links() }}
    @endif
@endsection
