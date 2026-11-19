@extends('layouts.app', ['titulo' => 'Notas fiscais'])
@section('conteudo')
    <div class="page-head"><div><h1>Notas fiscais</h1>
        <p class="subtitle">Todas as notas emitidas pelo sistema (das OS e avulsas), inclusive as canceladas. Notas recusadas pela Fazenda não contam: o número volta para a próxima tentativa.</p></div>
        <div class="page-acoes">
            <a class="button" href="{{ route('notas-fiscais.csv', request()->query()) }}">Baixar planilha</a>
            <a class="button" href="{{ route('notas-fiscais.xmls', request()->query()) }}">Baixar XMLs (ZIP)</a>
        </div></div>
    <form method="GET" class="filters">
        @include('partials.campo', ['nome' => 'situacao', 'rotulo' => 'Situação', 'tipo' => 'select', 'valorAtual' => $f['situacao'], 'opcoes' => \App\Http\Controllers\NotaFiscalController::SITUACOES])
        @include('partials.campo', ['nome' => 'tipo', 'rotulo' => 'Tipo', 'tipo' => 'select', 'valorAtual' => $f['tipo'] ?? '', 'opcoes' => ['' => 'NFS-e e NF-e'] + \App\Support\DadosFiscais::NOTAS])
        @include('partials.campo', ['nome' => 'de', 'rotulo' => 'De', 'tipo' => 'date', 'valorAtual' => $f['de'] ?? ''])
        @include('partials.campo', ['nome' => 'ate', 'rotulo' => 'Até', 'tipo' => 'date', 'valorAtual' => $f['ate'] ?? ''])
        @include('partials.campo', ['nome' => 'q', 'rotulo' => 'Número, chave, OS ou cliente', 'valorAtual' => $f['q'] ?? ''])
        <button class="button" type="submit">Filtrar</button>
    </form>
    @if ($notas->isEmpty())
        <p class="muted">Nenhuma nota neste filtro.</p>
    @else
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Nota</th><th>Data</th><th>Cliente</th><th>Origem</th><th class="num">Valor</th><th>Situação</th><th><span class="sr-only">Arquivos</span></th></tr></thead>
            <tbody>
            @foreach ($notas as $d)
                @php($cli = $d->ordem?->cliente ?? $d->notaAvulsa?->cliente)
                @php($baixar = fn (array $extra = []) => $d->ordem ? route('fiscal.baixar', [$d->ordem, $d] + $extra) : route('notas-avulsas.baixar', [$d->notaAvulsa, $d] + $extra))
                <tr>
                    <td data-label="Nota"><strong>{{ $d->tipo === 'nfe' ? 'NF-e' : 'NFS-e' }} nº {{ $d->numero_dps ?? $d->numero ?? '—' }}</strong><br><small class="muted">série {{ $d->serie ?? '—' }}{{ $d->ambiente === 2 ? ' · homologação' : '' }}</small></td>
                    <td data-label="Data">{{ \App\Support\Fuso::exibir($d->autorizado_em ?? $d->created_at) }}</td>
                    <td data-label="Cliente">{{ $cli?->nome ?? '—' }}</td>
                    <td data-label="Origem">@if ($d->ordem)<a href="{{ route('ordens.show', [$d->ordem, 'aba' => 'documentos']) }}">OS {{ $d->ordem->numero }}</a>@elseif ($d->notaAvulsa)<a href="{{ route('notas-avulsas.show', $d->notaAvulsa) }}">Avulsa {{ $d->notaAvulsa->referencia() }}</a>@endif</td>
                    <td data-label="Valor" class="num">{{ \App\Support\Dinheiro::formatar($d->valor_centavos) }}</td>
                    <td data-label="Situação"><span class="tag {{ in_array($d->status, ['cancelado', 'rejeitado'], true) ? 'off' : '' }}">{{ $d->rotuloStatus() }}</span>
                        @if ($d->status === 'cancelado')<br><small class="muted">em {{ \App\Support\Fuso::exibir($d->cancelado_em) }}: {{ \Illuminate\Support\Str::limit($d->cancelamento_motivo, 60) }}</small>@endif
                        @if ($d->status === 'rejeitado')<br><small class="muted">{{ \Illuminate\Support\Str::limit($d->mensagem, 80) }}</small>@endif</td>
                    <td data-label="Arquivos">@if ($d->arquivo)<a href="{{ $baixar() }}" target="_blank" rel="noopener">DANFE</a>@endif @if ($d->xml)<a href="{{ $baixar(['xml' => 1]) }}">XML</a>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @include('partials.paginacao', ['paginator' => $notas])
    @endif
    @if (preg_replace('/\D/', '', (string) \App\Models\Empresa::atual()->cnpj) === '69319961000160') {{-- notas anteriores ao sistema (SGA), conferidas nos XML em 19/11/2026 --}}
    <p class="hint">As NF-e nº 1 a 8 deste CNPJ (outubro/2026) foram emitidas pelo sistema antigo e não aparecem aqui: nº 1 e 2 canceladas em 08/10/2026; nº 3 a 8 autorizadas.</p>
    @endif
@endsection
