@extends('layouts.cliente', ['titulo' => 'Orçamento '.$o->numero])
@section('conteudo')
    <p class="muted">{{ \App\Models\Empresa::atual()->nome_fantasia ?: $o->unidade->nome }}</p>
    <h1>Olá, {{ strtok($o->cliente->nome, ' ') }}</h1>
    <p class="subtitle">Orçamento {{ $o->numero }} · {{ $o->veiculoTexto() }} · válido até {{ $o->validade->format('d/m/Y') }}</p>
    @if ($o->status === 'aprovado' || $o->status === 'convertido')
        <p class="alert sucesso" role="status">Orçamento aprovado. Combine com a oficina a entrega do veículo.</p>
    @elseif ($o->status === 'recusado')
        <p class="alert" role="status">Orçamento recusado.</p>
    @elseif ($o->vencido())
        <p class="alert erro" role="status">Este orçamento venceu. Peça um novo à oficina.</p>
    @endif
    <section class="form-panel" style="margin:20px 0"><div class="form-section">
        <h2>Itens</h2>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Descrição</th><th class="num">Qtd.</th><th class="num">Preço</th><th class="num">Total</th></tr></thead>
            <tbody>@foreach ($o->itens as $i)
                <tr><td data-label="Descrição">{{ $i['descricao'] }}</td><td data-label="Qtd." class="num">{{ \App\Support\Dinheiro::quantidadeExibicao((string) $i['quantidade']) }}</td>
                    <td data-label="Preço" class="num">{{ \App\Support\Dinheiro::formatar((int) $i['preco_unitario_centavos']) }}</td><td data-label="Total" class="num">{{ \App\Support\Dinheiro::formatar((int) $i['total_centavos']) }}</td></tr>
            @endforeach</tbody>
            <tfoot><tr><th colspan="3">Total</th><th class="num">{{ \App\Support\Dinheiro::formatar($o->total_centavos) }}</th></tr></tfoot>
        </table></div>
        @if ($o->observacao)<p>{{ $o->observacao }}</p>@endif
    </div></section>
    @if ($o->status === 'enviado' && ! $o->vencido())<p>Para aprovar, responda a mensagem da oficina pelo WhatsApp. Valores sujeitos a confirmação após a avaliação do veículo na oficina.</p>@endif
    <p class="hint">Não é documento fiscal.</p>
@endsection
