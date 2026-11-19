@extends('layouts.app', ['titulo' => 'Nota avulsa '.$nota->referencia()])
@section('conteudo')
    <div class="page-head"><div><h1>Nota avulsa {{ $nota->referencia() }}</h1>
        <p class="subtitle">{{ \App\Models\NotaAvulsa::OPERACOES[$nota->operacao] }} · {{ $nota->unidade->nome }} · {{ \App\Support\Fuso::exibir($nota->created_at) }}</p></div>
        <div class="page-acoes"><a class="button" href="{{ route('notas-avulsas.index') }}">Todas as notas avulsas</a></div></div>

    <section class="form-panel" style="margin-bottom:20px"><section class="form-section">
        <h2>Destinatário</h2>
        <p><strong>{{ $nota->cliente->nome }}</strong> · CPF/CNPJ {{ $nota->cliente->documento ?: 'não informado' }}<br>
            <span class="muted">{{ $nota->cliente->enderecoTexto() ?: 'Endereço não informado' }}</span></p>
        <p class="hint">{{ \App\Models\NotaAvulsa::CANAIS[$nota->canal] }} · Frete: {{ \App\Models\NotaAvulsa::FRETES[$nota->frete] }} · Pagamento: {{ \App\Models\NotaAvulsa::PAGAMENTOS[$nota->pagamento] }}</p>
    </section><section class="form-section">
        <h2>Peças</h2>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Descrição</th><th>NCM</th><th class="num">Qtd.</th><th class="num">Unitário</th><th class="num">Total</th></tr></thead>
            <tbody>
            @foreach ($nota->itens as $i)
                <tr><td data-label="Descrição">{{ $i['descricao'] }}</td><td data-label="NCM">{{ $i['ncm'] ?: 'padrão' }}</td>
                    <td data-label="Qtd." class="num">{{ \App\Support\Dinheiro::quantidadeExibicao((string) $i['quantidade']) }}</td>
                    <td data-label="Unitário" class="num">{{ \App\Support\Dinheiro::formatar($i['preco_unitario_centavos']) }}</td>
                    <td data-label="Total" class="num">{{ \App\Support\Dinheiro::formatar($i['total_centavos']) }}</td></tr>
            @endforeach
            </tbody>
            <tfoot><tr><th colspan="4">Total da nota</th><th class="num">{{ \App\Support\Dinheiro::formatar($nota->valor_centavos) }}</th></tr></tfoot>
        </table></div>
        @if ($nota->observacao)<p class="hint">Observação na nota: {{ $nota->observacao }}</p>@endif
    </section></section>

    @if ($nota->documentos->whereIn('status', ['pendente', 'processando'])->isNotEmpty())<meta http-equiv="refresh" content="5">@endif
    <section class="form-panel"><section class="form-section" id="fiscal">
        <h2>NF-e</h2>
        @include('ordens._fiscal', ['nota' => $nota, 'docs' => $nota->documentos])
    </section></section>
@endsection
