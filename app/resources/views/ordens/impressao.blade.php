@php
    $o = $ordem;
    $fmt = fn ($c) => \App\Support\Dinheiro::formatar((int) $c);
    $meios = ['pix' => 'Pix', 'pix_manual' => 'Pix', 'dinheiro' => 'Dinheiro', 'cartao_externo' => 'Cartão'];
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <title>OS {{ $o->numero }} · {{ $loja['nome'] }}</title>
    <style>:root{--cor:{{ $loja['cor'] }}}{!! file_get_contents(resource_path('css/impressao.css')) !!}</style>
</head>
<body>
<div class="barra">
    <p>Confira os dados e imprima ou salve como PDF pelo navegador.</p>
    <span>
        @if ($interno)<a href="{{ route('ordens.show', $o) }}">Voltar à OS</a>@else<a href="{{ route('portal.show', $token) }}">Voltar ao atendimento</a>@endif
        @if ($interno)
            <form method="POST" action="{{ route('ordens.os.whatsapp', $o) }}">@csrf<input type="hidden" name="canal" value="sistema"><button type="submit" class="secundario">Enviar por WhatsApp</button></form>
            <form method="POST" action="{{ route('ordens.os.whatsapp', $o) }}" target="_blank">@csrf<input type="hidden" name="canal" value="manual"><button type="submit" class="secundario">Abrir no meu WhatsApp</button></form>
        @endif
        <button type="button" onclick="window.print()">Imprimir ou salvar PDF</button>
    </span>
</div>
<main class="folha">
    <header class="cabecalho">
        <div class="loja">
            @if ($loja['logo'])<img src="{{ $loja['logo'] }}" alt="Logo {{ $loja['nome'] }}">@endif
            <div>
                <h1>{{ $loja['nome'] }}</h1>
                @if ($loja['razao_social'])<p>{{ $loja['razao_social'] }}</p>@endif
                <p>@if ($loja['cnpj'])CNPJ {{ $loja['cnpj'] }} · @endif{{ $loja['unidade'] }}</p>
                @if ($loja['telefone'] || $loja['email'])<p>{{ collect(['Tel. '.$loja['telefone'], $loja['email']])->filter(fn ($v) => $v && $v !== 'Tel. ')->join(' · ') }}</p>@endif
                @if ($loja['endereco'])<p>{{ $loja['endereco'] }}</p>@endif
            </div>
        </div>
        <div class="etiqueta">
            <small>Ordem de serviço</small>
            <strong>{{ $o->numero }}</strong>
            <span>Entrada {{ \App\Support\Fuso::exibir($o->entrada_em, 'd/m/Y') }}</span>
        </div>
    </header>
    <p class="aviso-fiscal">Não é documento fiscal</p>

    <section class="blocos">
        <div class="bloco">
            <h2>Cliente</h2>
            <p><strong>{{ $o->cliente->nome }}</strong></p>
            <p>{{ $o->cliente->telefoneFormatado() }}</p>
            @if (\App\Support\Endereco::preenchido($o->cliente))<p>{{ $o->cliente->enderecoTexto() }}</p>@endif
        </div>
        <div class="bloco">
            @if ($o->veiculo)
                <h2>Veículo</h2>
                <p><strong>Placa {{ $o->veiculo->placaFormatada() }}</strong></p>
                <p>{{ collect([$o->veiculo->modelo, $o->veiculo->cor, $o->quilometragem !== null ? number_format($o->quilometragem, 0, ',', '.').' km' : null])->filter()->join(' · ') ?: 'Modelo não informado' }}</p>
            @else
                <h2>Item (sem veículo)</h2>
                <p><strong>{{ $o->item_descricao }}</strong></p>
            @endif
        </div>
        <div class="bloco">
            <h2>Prazo de entrega</h2>
            <p>{{ $o->entrega_prevista_em ? $o->entrega_prevista_em->format('d/m/Y') : 'A combinar' }}</p>
        </div>
        <div class="bloco">
            <h2>Data de emissão</h2>
            <p>{{ \App\Support\Fuso::exibir(now(), 'd/m/Y H:i') }}</p>
        </div>
    </section>

    <section class="secao">
        <h2>Serviços @if ($orcamento && ! $aprovado)<span class="estado">Aguardando aprovação</span>@endif</h2>
        @if ($orcamento)
            <table>
                <thead><tr><th>Qtd.</th><th>Descrição</th><th class="num">Valor unit.</th><th class="num">Total</th></tr></thead>
                <tbody>
                @foreach ($orcamento->itens as $i)
                    <tr><td>{{ \App\Support\Dinheiro::quantidadeExibicao($i->quantidade) }}</td><td>{{ $i->descricao }}</td>
                        <td class="num">{{ $fmt($i->preco_unitario_centavos) }}</td><td class="num">{{ $fmt($i->total_centavos) }}</td></tr>
                @endforeach
                </tbody>
            </table>
            <table class="totais">
                <tr><td>Subtotal</td><td class="num">{{ $fmt($orcamento->subtotal_centavos) }}</td></tr>
                <tr><td>Desconto</td><td class="num">{{ $orcamento->desconto_centavos ? '− '.$fmt($orcamento->desconto_centavos) : $fmt(0) }}</td></tr>
                <tr class="total"><td>{{ $aprovado ? 'Total aprovado' : 'Total do orçamento' }}</td><td class="num">{{ $fmt($orcamento->total_centavos) }}</td></tr>
            </table>
        @else
            <p class="vazio">Ainda sem orçamento enviado. Motivo da visita: {{ $o->motivo }}</p>
        @endif
    </section>

    <section class="secao">
        <h2>Recebimentos</h2>
        @if ($pagamentos->isEmpty())
            <p class="vazio">Nenhum recebimento registrado.</p>
        @else
            <table>
                <thead><tr><th>Data</th><th>Forma</th><th class="num">Valor</th></tr></thead>
                <tbody>
                @foreach ($pagamentos as $p)
                    <tr><td>{{ \App\Support\Fuso::exibir($p->recebido_em, 'd/m/Y') }}</td><td>{{ $meios[$p->meio] ?? $p->meio }}</td><td class="num">{{ $fmt($p->valor_centavos) }}</td></tr>
                @endforeach
                @foreach ($devolucoes as $d)
                    <tr><td>{{ \App\Support\Fuso::exibir($d->created_at, 'd/m/Y') }}</td><td>Devolução</td><td class="num">− {{ $fmt($d->valor_centavos) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endif
        <table class="totais">
            <tr><td>Recebido</td><td class="num">{{ $fmt($resumo['recebido'] - $resumo['devolvido']) }}</td></tr>
            <tr class="total"><td>Saldo a pagar</td><td class="num">{{ $fmt(max(0, $resumo['saldo'])) }}</td></tr>
        </table>
    </section>

    @php($obs = collect([$orcamento?->observacao_cliente, $interno ? $o->observacoes : null])->filter())
    @if ($obs->isNotEmpty())
        <section class="secao"><h2>Observações</h2>@foreach ($obs as $t)<p class="obs">{{ $t }}</p>@endforeach</section>
    @endif

    <section class="assinaturas">
        <div>{{ $o->vendedor?->name ?? ' ' }}<small>Vendedor · {{ $loja['nome'] }}</small></div>
        <div>{{ $o->cliente->nome }}<small>Cliente</small></div>
    </section>

    <footer class="rodape">Não é documento fiscal. Nota fiscal, quando solicitada, é emitida à parte. OS {{ $o->numero }} · {{ $loja['unidade'] }}.</footer>
</main>
</body>
</html>
