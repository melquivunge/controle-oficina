@extends('layouts.app', ['titulo' => 'Orçamento '.$o->numero])
@section('conteudo')
    <div class="page-head"><div><h1>Orçamento {{ $o->numero }} <span class="tag">{{ $o->rotuloSituacao() }}</span></h1>
        <p class="subtitle">Sem entrada do veículo · {{ $o->unidade->nome }} · válido até {{ $o->validade->format('d/m/Y') }}@if (in_array($o->status, ['rascunho', 'enviado'], true) && $o->vencido()) <span class="tag off">vencido</span>@endif</p></div>
        <div class="page-acoes"><a class="button" href="{{ route('orcamentos-avulsos.index') }}">Todos os orçamentos sem entrada</a></div></div>
    @error('envio')<p class="alert erro" role="alert">{{ $message }}</p>@enderror
    @error('decisao')<p class="alert erro" role="alert">{{ $message }}</p>@enderror

    <section class="form-panel" style="margin-bottom:20px"><div class="form-section">
        <h2>Cliente e veículo</h2>
        <dl class="detalhes">
            <dt>Cliente</dt><dd><a href="{{ route('clientes.show', $o->cliente) }}">{{ $o->cliente->nome }}</a> · {{ $o->cliente->telefoneFormatado() }}</dd>
            <dt>Veículo</dt><dd>{{ $o->veiculoTexto() }}</dd>
            @if ($o->conversa)<dt>WhatsApp</dt><dd><a href="{{ route('whatsapp.show', $o->conversa) }}">Abrir a conversa</a></dd>@endif
            @if ($o->ordem)<dt>OS</dt><dd><a href="{{ route('ordens.show', $o->ordem) }}">{{ $o->ordem->numero }}</a></dd>@endif
            @if ($o->decidido_em)<dt>Decisão</dt><dd>{{ $o->rotuloSituacao() === 'Recusado' ? 'Recusado' : 'Aprovado' }} em {{ \App\Support\Fuso::exibir($o->decidido_em) }} ({{ \App\Models\OrcamentoAvulso::CANAIS[$o->decisao_canal] ?? $o->decisao_canal }}){{ $o->decisao_observacao ? ' · '.$o->decisao_observacao : '' }}</dd>@endif
        </dl>
    </div></section>

    <section class="form-panel" style="margin-bottom:20px"><div class="form-section">
        <h2>Itens</h2>
        @if ($o->status === 'rascunho')
            <livewire:orcamento-editor :avulso="$o" :key="'avulso-'.$o->id.'-'.$o->updated_at->timestamp" />
        @else
            <div class="table-wrap"><table class="data-table">
                <thead><tr><th>Descrição</th><th class="num">Qtd.</th><th class="num">Preço</th><th class="num">Total</th></tr></thead>
                <tbody>@foreach ($o->itens as $i)
                    <tr><td data-label="Descrição">{{ $i['descricao'] }}</td><td data-label="Qtd." class="num">{{ \App\Support\Dinheiro::quantidadeExibicao((string) $i['quantidade']) }}</td>
                        <td data-label="Preço" class="num">{{ \App\Support\Dinheiro::formatar((int) $i['preco_unitario_centavos']) }}</td><td data-label="Total" class="num">{{ \App\Support\Dinheiro::formatar((int) $i['total_centavos']) }}</td></tr>
                @endforeach</tbody>
                <tfoot><tr><th colspan="3">Total</th><th class="num">{{ \App\Support\Dinheiro::formatar($o->total_centavos) }}</th></tr></tfoot>
            </table></div>
            @if ($o->observacao)<p class="hint">Observação: {{ $o->observacao }}</p>@endif
        @endif
    </div></section>

    @if (in_array($o->status, ['rascunho', 'enviado'], true) && $o->itens)
        <section class="helper" style="margin-bottom:20px">
            <h2>{{ $o->status === 'enviado' ? 'Enviar de novo' : 'Enviar ao cliente' }}</h2>
            <p>O cliente recebe o total, a validade e um link para ver os itens. Ele responde pelo WhatsApp e você registra a decisão aqui.{{ $o->status === 'enviado' ? ' Um novo envio troca o link: o anterior deixa de funcionar.' : '' }}</p>
            <div class="inline-form">
                <form method="POST" action="{{ route('orcamentos-avulsos.enviar', $o) }}">@csrf<input type="hidden" name="canal" value="loja">
                    <button class="button primary" type="submit">{{ $o->conversa ? 'Enviar na conversa do WhatsApp' : 'Enviar pelo WhatsApp da loja' }}</button></form>
                <form method="POST" action="{{ route('orcamentos-avulsos.enviar', $o) }}" target="_blank">@csrf<input type="hidden" name="canal" value="manual">
                    <button class="button" type="submit">Abrir no meu WhatsApp</button></form>
            </div>
        </section>
    @endif

    @if ($o->status === 'enviado')
        <section class="form-panel" style="margin-bottom:20px"><div class="form-section">
            <h2>Resposta do cliente</h2>
            <form method="POST" action="{{ route('orcamentos-avulsos.decidir', $o) }}">@csrf
                <div class="fields">
                    @include('partials.campo', ['nome' => 'canal', 'rotulo' => 'Como o cliente respondeu', 'tipo' => 'select', 'opcoes' => \App\Models\OrcamentoAvulso::CANAIS])
                    @include('partials.campo', ['nome' => 'observacao', 'rotulo' => 'Observação (opcional)', 'atributos' => ['maxlength' => 500]])
                </div>
                <div class="inline-form">
                    <button class="button primary" type="submit" name="decisao" value="aprovado">Cliente aprovou</button>
                    <button class="button" type="submit" name="decisao" value="recusado">Cliente recusou</button>
                </div>
            </form>
        </div></section>
    @endif

    @if ($o->status === 'aprovado')
        @can('registrar_entrada')
            <section class="helper" style="margin-bottom:20px"><h2>O carro chegou?</h2>
                <p>Faça a entrada normalmente (fotos, quilometragem). A OS fica com o número {{ $o->numero }} e os itens entram como rascunho do orçamento, para conferir depois do diagnóstico.</p>
                <a class="button primary" href="{{ route('entradas.nova', ['orcamento' => $o->numero]) }}">Dar entrada do veículo</a></section>
        @endcan
    @endif

    @if (! in_array($o->status, ['convertido', 'cancelado'], true))
        <details class="form-panel"><summary>Cancelar orçamento</summary>
            <form method="POST" action="{{ route('orcamentos-avulsos.cancelar', $o) }}" class="form-section">@csrf
                @include('partials.campo', ['nome' => 'motivo', 'rotulo' => 'Motivo', 'obrigatorio' => true, 'id' => 'cancelar-motivo'])
                <button class="button" type="submit">Cancelar orçamento</button>
            </form>
        </details>
    @endif
@endsection
