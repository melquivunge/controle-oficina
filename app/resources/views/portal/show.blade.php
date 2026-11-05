@extends('layouts.cliente', ['titulo' => 'Seu atendimento'])
@section('conteudo')
    <p class="muted">{{ $ordem->unidade->nome }}</p>
    <h1>Olá, {{ strtok($ordem->cliente->nome, ' ') }}</h1>
    <p class="subtitle">{{ collect($ordem->veiculo ? [$ordem->veiculo->modelo, 'placa '.$ordem->veiculo->placaFormatada()] : ['item: '.$ordem->item_descricao])->push('atendimento '.$ordem->numero)->filter()->join(' · ') }}</p>
    @if (session('sucesso'))<p class="alert sucesso" role="status">{{ session('sucesso') }}</p>@endif
    @if ($errors->any())<p class="alert erro" role="alert">{{ $errors->first() }}</p>@endif

    <p><a class="button" href="{{ route('portal.impressao', $token) }}">Ver ordem de serviço para imprimir</a></p>
    <section class="form-panel" style="margin:20px 0">
        <div class="form-section">
            <h2>Andamento</h2>
            <p>@include('partials.status', ['status' => $ordem->status])</p>
            <p class="hint">Entrada em {{ \App\Support\Fuso::exibir($ordem->entrada_em) }}.</p>
        </div>
    </section>

    @if ($documentos->isNotEmpty())
        <section class="form-panel" style="margin-bottom:20px"><div class="form-section">
            <h2>Documentos</h2>
            @foreach ($documentos as $d)
                <p><a href="{{ route('portal.documento', [$token, $d->id]) }}">Documento {{ $d->numero }}</a> · {{ $d->rotuloStatus() }}@if ($d->provedor === 'simulado') <span class="tag extra">SEM VALIDADE FISCAL (simulação)</span>@endif</p>
            @endforeach
        </div></section>
    @endif
    @if ($cobranca)
        <section class="form-panel" style="margin-bottom:20px"><div class="form-section">
            <h2>Pagamento via Pix · {{ \App\Support\Dinheiro::formatar($cobranca->valor_centavos) }}</h2>
            <p>Copie o código abaixo no aplicativo do seu banco. O pagamento vai para a conta oficial da oficina e é confirmado automaticamente.</p>
            <p class="code-box">{{ $cobranca->copia_e_cola }}</p>
            <p class="hint">Válido até {{ \App\Support\Fuso::exibir($cobranca->expira_em) }}. Não pague para outra chave Pix informada por mensagem ou pessoalmente.</p>
        </div></section>
    @endif
    @if ($pendente)
        <section class="form-panel">
            <div class="form-section">
                <h2>Orçamento para sua aprovação · versão {{ $pendente->versao }}</h2>
                @if ($ordem->diagnostico)<p style="white-space:pre-line">{{ $ordem->diagnostico }}</p>@endif
                <div class="table-wrap"><table class="data-table">
                    <thead><tr><th>Serviço ou peça</th><th>Qtd.</th><th>Preço unitário</th><th>Total</th></tr></thead>
                    <tbody>
                    @foreach ($pendente->itens as $i)
                        <tr><td data-label="Serviço">{{ $i->descricao }} @if($i->extra)<span class="tag extra">novo</span>@endif</td><td data-label="Qtd.">{{ \App\Support\Dinheiro::quantidadeExibicao($i->quantidade) }}</td>
                            <td data-label="Preço unitário">{{ \App\Support\Dinheiro::formatar($i->preco_unitario_centavos) }}</td><td data-label="Total">{{ \App\Support\Dinheiro::formatar($i->total_centavos) }}</td></tr>
                    @endforeach
                    </tbody>
                </table></div>
                <dl class="totais">
                    <dt>Subtotal</dt><dd>{{ \App\Support\Dinheiro::formatar($pendente->subtotal_centavos) }}</dd>
                    <dt>Desconto</dt><dd>{{ \App\Support\Dinheiro::formatar($pendente->desconto_centavos) }}</dd>
                    <dt>Total desta versão</dt><dd><strong>{{ \App\Support\Dinheiro::formatar($pendente->total_centavos) }}</strong></dd>
                </dl>
                @if ($pendente->observacao_cliente)<p class="notice">{{ $pendente->observacao_cliente }}</p>@endif
                <p class="hint">Nenhum serviço fora desta lista será feito sem uma nova aprovação sua.</p>
                <form method="POST" action="{{ route('portal.decidir', $token) }}">
                    @csrf
                    <input type="hidden" name="orcamento_id" value="{{ $pendente->id }}">
                    <div class="field"><label for="observacao">Comentário para a oficina (opcional)</label><input id="observacao" name="observacao" maxlength="500"></div>
                    <div class="decisao">
                        <button class="button primary" type="submit" name="decisao" value="aprovado">Aprovar {{ \App\Support\Dinheiro::formatar($pendente->total_centavos) }}</button>
                        <button class="button" type="submit" name="decisao" value="recusado">Recusar este orçamento</button>
                    </div>
                </form>
            </div>
        </section>
    @elseif ($aprovado)
        <section class="form-panel"><div class="form-section">
            <h2>Orçamento aprovado · versão {{ $aprovado->versao }}</h2>
            <ul>@foreach ($aprovado->itens as $i)<li>{{ \App\Support\Dinheiro::quantidadeExibicao($i->quantidade) }} × {{ $i->descricao }} — {{ \App\Support\Dinheiro::formatar($i->total_centavos) }}</li>@endforeach</ul>
            <p><strong>Total aprovado: {{ \App\Support\Dinheiro::formatar($aprovado->total_centavos) }}</strong></p>
        </div></section>
    @else
        <p class="muted">Ainda não há orçamento para aprovar. Você receberá uma mensagem quando estiver pronto.</p>
    @endif
@endsection
