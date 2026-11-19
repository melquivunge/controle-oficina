@extends('layouts.app', ['titulo' => 'Notas avulsas (peças)'])
@section('conteudo')
    <div class="page-head"><div><h1>Notas avulsas (peças)</h1>
        <p class="subtitle">NF-e de venda ou de remessa de peças sem ordem de serviço. Exemplo: peça vendida no Mercado Livre e enviada pelos Correios.</p></div>
        <div class="page-acoes"><a class="button primary" href="{{ route('notas-avulsas.create') }}">Nova nota avulsa</a></div></div>
    @if ($notas->isEmpty())
        <p class="muted">Nenhuma nota avulsa emitida ainda.</p>
    @else
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Referência</th><th>Cliente</th><th>Operação</th><th>Unidade</th><th class="num">Valor</th><th>Nota</th><th>Data</th></tr></thead>
            <tbody>
            @foreach ($notas as $n)
                @php($doc = $n->documentos->first())
                <tr>
                    <td data-label="Referência"><a href="{{ route('notas-avulsas.show', $n) }}"><strong>{{ $n->referencia() }}</strong></a></td>
                    <td data-label="Cliente">{{ $n->cliente->nome }}<br><small class="muted">{{ $n->cliente->cidade }}{{ $n->cliente->uf ? '/'.$n->cliente->uf : '' }}</small></td>
                    <td data-label="Operação">{{ \App\Models\NotaAvulsa::OPERACOES[$n->operacao] }}</td>
                    <td data-label="Unidade">{{ $n->unidade->nome }}</td>
                    <td data-label="Valor" class="num">{{ \App\Support\Dinheiro::formatar($n->valor_centavos) }}</td>
                    <td data-label="Nota">@if ($doc)<span class="tag {{ $doc->status === 'rejeitado' ? 'off' : '' }}">{{ $doc->numero ? 'Nº '.$doc->numero.' · ' : '' }}{{ $doc->rotuloStatus() }}</span>@endif</td>
                    <td data-label="Data">{{ \App\Support\Fuso::exibir($n->created_at) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @include('partials.paginacao', ['paginator' => $notas])
    @endif
@endsection
