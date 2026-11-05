@extends('layouts.app', ['titulo' => 'Histórico de ações'])
@section('conteudo')
    <div class="page-head"><div><h1>Histórico de ações</h1><p class="subtitle">Registros permanentes: não podem ser editados nem apagados.</p></div>
        <a class="button" href="{{ route('auditoria.exportar', request()->only('unidade', 'acao', 'de', 'ate')) }}" download>Exportar CSV</a></div>
    <form method="GET" class="filters">
        @if ($unidades->count() > 1)
            @include('partials.campo', ['nome' => 'unidade', 'rotulo' => 'Unidade', 'tipo' => 'select', 'opcoes' => ['' => 'Todas'] + $unidades->pluck('nome', 'id')->all(), 'valorAtual' => request('unidade')])
        @endif
        @include('partials.campo', ['nome' => 'acao', 'rotulo' => 'Ação', 'tipo' => 'select', 'opcoes' => ['' => 'Todas'] + $acoes->mapWithKeys(fn ($a) => [$a => \App\Support\Acoes::rotulo($a)])->all(), 'valorAtual' => request('acao')])
        @include('partials.campo', ['nome' => 'de', 'rotulo' => 'De', 'tipo' => 'date', 'valorAtual' => request('de')])
        @include('partials.campo', ['nome' => 'ate', 'rotulo' => 'Até', 'tipo' => 'date', 'valorAtual' => request('ate')])
        <button class="button" type="submit">Filtrar</button>
    </form>
    @if ($eventos->isEmpty())
        <p class="muted">Nenhum registro encontrado para estes filtros.</p>
    @else
    <div class="table-wrap">
        <table class="data-table linha-tempo">
            <thead><tr><th>Quando</th><th>Quem</th><th>Unidade</th><th>Ação</th><th>Registro</th><th>Motivo e detalhes</th></tr></thead>
            <tbody>
            @foreach ($eventos as $e)
                <tr>
                    <td data-label="Quando">{{ \App\Support\Fuso::exibir($e->ocorrido_em, 'd/m/Y H:i:s') }}</td>
                    <td data-label="Quem">{{ $e->ator?->name ?? ucfirst($e->ator_tipo) }}</td>
                    <td data-label="Unidade">{{ $e->unidade?->nome ?? '—' }}</td>
                    <td data-label="Ação">{{ \App\Support\Acoes::rotulo($e->acao) }}</td>
                    <td data-label="Registro">{{ $e->entidade_tipo ? str_replace('_', ' ', $e->entidade_tipo).' #'.$e->entidade_id : '—' }}</td>
                    <td data-label="Detalhes">
                        {{ $e->motivo }}
                        @if ($e->antes || $e->depois)
                            <details class="json"><summary>Antes/depois</summary>
                                <pre>Antes: {{ json_encode($e->antes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}
Depois: {{ json_encode($e->depois, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                            </details>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    {{ $eventos->links() }}
    @endif
@endsection
