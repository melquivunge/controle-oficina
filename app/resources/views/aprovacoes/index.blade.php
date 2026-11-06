@extends('layouts.app', ['titulo' => 'Aprovações de desconto'])
@section('conteudo')
    <div class="page-head"><div><h1>Aprovações de desconto</h1><p class="subtitle">Orçamentos com desconto acima do limite do catálogo (ou da regra geral) só podem ser concluídos depois de aprovados aqui. Quem pediu não decide o próprio pedido.</p></div></div>

    <h2>Pendentes</h2>
    @forelse ($pendentes as $p)
        <article class="versao pedido-desconto">
            <header>
                <strong>{{ $p->ordem->numero }} · versão {{ $p->versao }}@if ($p->ordem->veiculo) <span class="plate">{{ $p->ordem->veiculo->placaFormatada() }}</span>@endif</strong>
                <span class="tag">{{ $p->unidade->nome }}</span>
            </header>
            <p>Pedido de <strong>{{ $p->solicitante?->name }}</strong> em {{ \App\Support\Fuso::exibir($p->solicitado_em) }} · subtotal {{ \App\Support\Dinheiro::formatar((int) $p->subtotal_centavos) }} · total {{ \App\Support\Dinheiro::formatar((int) $p->total_centavos) }} · maior desconto <strong>{{ str_replace('.', ',', $p->maior_desconto_percentual) }}%</strong></p>
            <ul>
                @foreach ($p->excessos as $e)
                    <li>{{ $e['descricao'] }}: desconto {{ str_replace('.', ',', $e['desconto_percentual']) }}% (limite {{ str_replace('.', ',', $e['limite_percentual']) }}%)</li>
                @endforeach
            </ul>
            @if ($p->motivo)<p class="hint">Motivo informado: {{ $p->motivo }}</p>@endif
            <p><a href="{{ route('ordens.show', [$p->ordem, 'aba' => 'orcamento']) }}">Ver o orçamento completo</a></p>
            <form method="POST" action="{{ route('aprovacoes.decidir', $p) }}" class="decisao-desconto">
                @csrf
                @include('partials.campo', ['nome' => 'justificativa', 'id' => 'justificativa-'.$p->id, 'rotulo' => 'Justificativa (obrigatória)', 'obrigatorio' => true, 'atributos' => ['maxlength' => 500, 'minlength' => 5]])
                <div class="inline-form">
                    <button class="button primary" type="submit" name="decisao" value="aprovada">Aprovar desconto</button>
                    <button class="button" type="submit" name="decisao" value="recusada">Recusar</button>
                </div>
            </form>
        </article>
    @empty
        <p class="muted">Nenhum pedido pendente para você decidir.</p>
    @endforelse

    @if ($decididas->isNotEmpty())
        <h2 style="margin-top:24px">Decididos recentemente</h2>
        <div class="table-wrap"><table class="data-table">
            <thead><tr><th>Ordem</th><th>Decisão</th><th>Quem pediu</th><th>Quem decidiu</th><th>Justificativa</th></tr></thead>
            <tbody>
            @foreach ($decididas as $d)
                <tr>
                    <td data-label="Ordem">{{ $d->ordem->numero }} · v{{ $d->versao }} · {{ str_replace('.', ',', $d->maior_desconto_percentual) }}%</td>
                    <td data-label="Decisão"><span @class(['tag', 'off' => $d->status === 'recusada'])>{{ $d->rotuloStatus() }}</span> {{ \App\Support\Fuso::exibir($d->decidido_em) }}</td>
                    <td data-label="Quem pediu">{{ $d->solicitante?->name }}</td>
                    <td data-label="Quem decidiu">{{ $d->decisor?->name }}</td>
                    <td data-label="Justificativa">{{ $d->justificativa }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif
@endsection
