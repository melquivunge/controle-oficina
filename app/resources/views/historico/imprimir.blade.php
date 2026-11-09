{{-- OS do sistema antigo em linguagem simples, pronta para imprimir (09/11/2026). Só consulta; não é documento fiscal. --}}
@php
    $dinheiro = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
    $data = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d/m/Y') : 'não informada';
    $situacao = ($os['CANCELADA'] ?? '') === 'S' ? 'Cancelada' : (($os['STATUS'] ?? '') === 'F' ? 'Concluída' : 'Em aberto');
    $verValores = auth()->user()->can('ver_valores');
    $empresa = \App\Models\Empresa::atual();
    $qtd = fn ($q) => rtrim(rtrim(number_format((float) ($q ?: 1), 3, ',', '.'), '0'), ',');
    $linhas = $itens->map(fn ($i) => [
        'tipo' => $i['tipo'],
        'nome' => trim($i['DESCRICAO'] ?? '') ?: ($i['_servico'] ?? $i['_produto'] ?? 'Item'),
        'qtd' => (float) ($i['QTD'] ?? 1) ?: 1.0,
        'preco' => (float) ($i['PRECO'] ?? 0),
        'desconto' => (float) ($i['DESCONTO'] ?? 0),
    ]);
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>OS {{ $os['CODIGO'] }} (sistema antigo)</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1d2433;background:#eef1f5}
        .barra{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 20px;background:#13203a;color:#fff;flex-wrap:wrap}
        .barra a{color:#fff}
        .barra button{font:inherit;font-weight:600;padding:10px 16px;border:0;border-radius:8px;background:#f4b400;color:#13203a;cursor:pointer;min-height:44px}
        .folha{max-width:800px;margin:20px auto;background:#fff;padding:32px 36px;border-radius:6px}
        header{display:flex;justify-content:space-between;gap:16px;border-bottom:3px solid #13203a;padding-bottom:12px;margin-bottom:20px}
        header h1{font-size:22px;margin:0}
        header small{display:block;color:#5b6475}
        .selo{align-self:flex-start;padding:4px 10px;border-radius:99px;font-weight:700;font-size:13px;background:#e3f6ee;color:#155c39;white-space:nowrap}
        .selo.cancelada{background:#f6e7e4;color:#8a2c1d}.selo.aberto{background:#fff3d6;color:#6b4e0e}
        h2{font-size:14px;text-transform:uppercase;letter-spacing:.06em;color:#5b6475;margin:22px 0 8px}
        .grade{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:6px 24px}
        .grade p{margin:0}.grade b{display:block;font-size:12px;color:#5b6475;font-weight:600}
        table{width:100%;border-collapse:collapse;margin-top:4px}
        th,td{padding:8px 6px;border-bottom:1px solid #e4e8ef;text-align:left;vertical-align:top}
        th{font-size:12px;color:#5b6475;font-weight:600}
        td.num,th.num{text-align:right;white-space:nowrap}
        .tipo{font-size:12px;color:#5b6475}
        .totais{margin-left:auto;max-width:320px;margin-top:12px}
        .totais p{display:flex;justify-content:space-between;margin:4px 0}
        .totais .total{font-size:18px;font-weight:700;border-top:2px solid #13203a;padding-top:6px}
        .obs{white-space:pre-line;background:#f6f7f9;padding:10px 12px;border-radius:6px;margin:0}
        footer{margin-top:28px;font-size:12px;color:#5b6475;border-top:1px solid #e4e8ef;padding-top:10px}
        @media print{body{background:#fff}.barra{display:none}.folha{margin:0;max-width:none;padding:0;border-radius:0}}
        @media (max-width:600px){.folha{padding:20px 16px;margin:0}header{flex-direction:column}}
    </style>
</head>
<body>
<div class="barra">
    <a href="{{ route('historico.show', $id) }}">← Voltar</a>
    <button type="button" onclick="window.print()">Imprimir ou salvar PDF</button>
</div>
<main class="folha">
    <header>
        <div>
            <h1>Ordem de serviço nº {{ $os['CODIGO'] }}</h1>
            <small>{{ $empresa->nome_fantasia ?: $empresa->razao_social }}@if ($empresa->cnpj) · CNPJ {{ $empresa->cnpjFormatado() }}@endif</small>
            <small>Registro do sistema anterior, impresso em {{ now()->format('d/m/Y') }}</small>
        </div>
        <span @class(['selo', 'cancelada' => $situacao === 'Cancelada', 'aberto' => $situacao === 'Em aberto'])>{{ $situacao }}</span>
    </header>

    <h2>Cliente</h2>
    <div class="grade">
        <p><b>Nome</b>{{ $cliente?->nome ?? 'não informado' }}</p>
        @can('ver_contato_cliente')
            <p><b>Telefone</b>{{ $cliente?->telefoneFormatado() ?: 'não informado' }}</p>
            @if ($cliente?->documentoFormatado())<p><b>{{ strlen($cliente->documento) === 11 ? 'CPF' : 'CNPJ' }}</b>{{ $cliente->documentoFormatado() }}</p>@endif
        @endcan
    </div>

    <h2>Veículo</h2>
    <div class="grade">
        <p><b>Placa</b>{{ $veiculo?->placaFormatada() ?? 'não informada' }}</p>
        <p><b>Carro</b>{{ $veiculo ? (trim($veiculo->marca.' '.$veiculo->modelo) ?: 'não informado') : 'não informado' }}</p>
        @if ($veiculo?->ano || $veiculo?->cor)<p><b>Ano / cor</b>{{ $veiculo->ano ?: '—' }} / {{ $veiculo->cor ?: '—' }}</p>@endif
        @if (! empty($os['KM']))<p><b>Quilometragem na entrada</b>{{ number_format((float) $os['KM'], 0, ',', '.') }} km</p>@endif
    </div>

    <h2>Datas</h2>
    <div class="grade">
        <p><b>Chegou na oficina</b>{{ $data($os['DATAIN'] ?? null) }}</p>
        <p><b>Saiu da oficina</b>{{ $data($os['DATAOUT'] ?? null) }}</p>
    </div>

    @if (! empty($os['DESCRICAO']) || ! empty($os['OBS']))
        <h2>O que o cliente pediu / observações</h2>
        <p class="obs">{{ trim(($os['DESCRICAO'] ?? '')."\n".($os['OBS'] ?? '')) }}</p>
    @endif

    <h2>O que foi feito</h2>
    @if ($linhas->isEmpty())
        <p>Nenhum serviço ou peça foi registrado nesta OS.</p>
    @else
        <table>
            <thead><tr><th>Serviço ou peça</th><th class="num">Quantidade</th>@if ($verValores)<th class="num">Valor unitário</th><th class="num">Total</th>@endif</tr></thead>
            <tbody>
            @foreach ($linhas as $l)
                <tr>
                    <td>{{ $l['nome'] }} <span class="tipo">({{ $l['tipo'] === 'Peça' ? 'peça' : 'serviço' }})</span></td>
                    <td class="num">{{ $qtd($l['qtd']) }}</td>
                    @if ($verValores)<td class="num">{{ $dinheiro($l['preco']) }}</td><td class="num">{{ $dinheiro($l['qtd'] * $l['preco'] - $l['desconto']) }}</td>@endif
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @if ($verValores)
        @php($desconto = (float) ($os['VL_DESC_SERV'] ?? 0) + (float) ($os['VL_DESC_PECA'] ?? 0))
        <div class="totais">
            <p><span>Serviços</span><span>{{ $dinheiro($os['VLSERVICO'] ?? 0) }}</span></p>
            <p><span>Peças</span><span>{{ $dinheiro($os['VLPECA'] ?? 0) }}</span></p>
            @if ($desconto > 0)<p><span>Desconto</span><span>− {{ $dinheiro($desconto) }}</span></p>@endif
            <p class="total"><span>Total</span><span>{{ $dinheiro($os['TOTAL'] ?? 0) }}</span></p>
            <p><span>Pagamento</span><span>{{ ($os['PAG'] ?? '') === 'S' ? 'Pago'.(! empty($os['DATAPAG']) ? ' em '.$data($os['DATAPAG']) : '') : 'Não consta como pago' }}</span></p>
        </div>
    @endif

    <footer>Cópia para consulta de um atendimento registrado no sistema anterior da oficina. Não é nota fiscal nem comprovante de pagamento.</footer>
</main>
</body>
</html>
