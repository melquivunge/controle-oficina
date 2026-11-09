{{-- Documento fiscal SIMULADO (layout inspirado no DANFSe). Arquivo privado, autocontido: sem script, sem recursos externos (CSP: estilos inline e imagens data:). --}}
@php($tarja = 'SEM VALIDADE FISCAL — SIMULAÇÃO')
@php($cor = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $empresa->cor_principal) ? $empresa->cor_principal : '#1f4e79')
@php($nomeEmpresa = $empresa->nome_fantasia ?: ($empresa->razao_social ?: 'Oficina'))
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $tarja }} · Documento {{ $numero }}</title>
<style>
@page { size: A4; margin: 0; }
* { box-sizing: border-box; }
:root { --tinta: #1d2430; --rotulo: #5b6472; --fio: #c9ced6; --fundo: #e9ecf0; --marca: {{ $cor }}; --alerta: #b3261e; --alerta-fundo: #fdecea; }
html { background: var(--fundo); }
body { margin: 0; color: var(--tinta); font: 9.5pt/1.4 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.folha { position: relative; width: 210mm; min-height: 297mm; margin: 10mm auto; padding: 12mm 12mm 16mm; background: #fff; box-shadow: 0 1px 3px rgba(20,30,45,.12), 0 12px 32px rgba(20,30,45,.10); overflow: hidden; display: flex; flex-direction: column; gap: 4mm; }
.marca-dagua { position: absolute; inset: -40mm; display: grid; place-content: center; gap: 34mm; transform: rotate(-30deg); pointer-events: none; z-index: 0; }
.marca-dagua span { display: block; white-space: nowrap; font-size: 30pt; font-weight: 800; letter-spacing: .08em; color: rgba(179,38,30,.085); text-align: center; }
.folha > :not(.marca-dagua) { position: relative; z-index: 1; }
.tarja { display: flex; align-items: center; justify-content: space-between; gap: 6mm; padding: 2.6mm 4mm; border: 1.5px solid var(--alerta); border-radius: 2mm; color: var(--alerta); font-weight: 800; letter-spacing: .06em;
    background: repeating-linear-gradient(-45deg, var(--alerta-fundo) 0 6px, #fff 6px 12px); }
.tarja strong { font-size: 12.5pt; background: #fff; padding: 0 2mm; }
.tarja small { font-size: 7.5pt; font-weight: 600; letter-spacing: .02em; background: #fff; padding: 0 2mm; text-align: right; }
.topo { display: grid; grid-template-columns: 1fr 64mm; gap: 4mm; align-items: stretch; }
.emitente { display: flex; gap: 4mm; align-items: center; padding: 3mm 0; border-bottom: 2.5px solid var(--marca); }
.emitente img { width: 20mm; height: 20mm; object-fit: contain; }
.monograma { width: 20mm; height: 20mm; display: grid; place-items: center; border-radius: 2mm; background: var(--marca); color: #fff; font-size: 18pt; font-weight: 800; }
.emitente h1 { margin: 0; font-size: 14pt; line-height: 1.15; }
.emitente p { margin: .6mm 0 0; color: var(--rotulo); font-size: 8.5pt; }
.titulo { border: 1px solid var(--tinta); border-radius: 1.5mm; padding: 3mm; display: flex; flex-direction: column; justify-content: center; gap: 1mm; }
.titulo b { font-size: 8pt; letter-spacing: .08em; text-transform: uppercase; }
.titulo .num { color: var(--tinta); font: 700 15pt/1.1 ui-monospace, "SF Mono", Menlo, Consolas, monospace; }
.titulo span { color: var(--rotulo); font-size: 7.5pt; }
.rotulo { display: block; color: var(--rotulo); font-size: 6.8pt; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; margin-bottom: .6mm; }
.grade { display: grid; border: 1px solid var(--fio); border-radius: 1.5mm; overflow: hidden; }
.grade > div { padding: 2.2mm 3mm; border-right: 1px solid var(--fio); min-width: 0; }
.grade > div:last-child { border-right: 0; }
.ids { grid-template-columns: 1.1fr .7fr 1fr 1.4fr 1.3fr; }
.mono { font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace; font-variant-numeric: tabular-nums; }
.partes { display: grid; grid-template-columns: 1fr 1fr; gap: 4mm; }
.parte { border: 1px solid var(--fio); border-radius: 1.5mm; padding: 3mm; }
.parte h2, .bloco h2 { margin: 0 0 2mm; font-size: 7.5pt; letter-spacing: .12em; text-transform: uppercase; color: #fff; background: var(--tinta); display: inline-block; padding: .8mm 2.2mm; border-radius: 1mm; }
.parte p { margin: 0 0 1mm; }
.parte .nome { font-weight: 700; font-size: 10.5pt; }
.parte .sec { color: var(--rotulo); font-size: 8.5pt; }
.bloco { border: 1px solid var(--fio); border-radius: 1.5mm; padding: 3mm; }
table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-size: 6.8pt; letter-spacing: .1em; text-transform: uppercase; color: var(--rotulo); border-bottom: 1.5px solid var(--tinta); padding: 1.6mm 1.5mm; }
td { padding: 2mm 1.5mm; border-bottom: 1px solid var(--fio); vertical-align: top; }
tbody tr:nth-child(even) td { background: #f6f7f9; }
.num-col { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.totais { display: grid; grid-template-columns: 1fr 62mm; gap: 4mm; align-items: stretch; }
.obs { border: 1px dashed var(--fio); border-radius: 1.5mm; padding: 3mm; color: var(--rotulo); font-size: 8pt; }
.obs p { margin: 0 0 1.2mm; }
.total { border: 2px solid var(--tinta); border-radius: 1.5mm; padding: 3mm; display: flex; flex-direction: column; justify-content: center; }
.total .valor { font: 800 17pt/1.1 ui-monospace, "SF Mono", Menlo, Consolas, monospace; }
.rodape { margin-top: auto; padding-top: 3mm; border-top: 1px solid var(--fio); display: flex; justify-content: space-between; gap: 6mm; color: var(--rotulo); font-size: 7.5pt; }
.rodape strong { color: var(--alerta); }
@media screen and (max-width: 820px) {
    .folha { width: auto; min-height: 0; margin: 0; padding: 6mm; box-shadow: none; }
    .topo, .partes, .totais { grid-template-columns: 1fr; }
    .ids { grid-template-columns: 1fr 1fr; }
    .ids > div { border-bottom: 1px solid var(--fio); }
    .tarja { flex-direction: column; align-items: flex-start; }
    .tarja small { text-align: left; }
    .rodape { flex-direction: column; }
}
@media print {
    html { background: #fff; }
    .folha { margin: 0; box-shadow: none; width: 210mm; height: 297mm; }
}
</style>
</head>
<body>
<main class="folha" aria-label="Documento simulado {{ $numero }}, sem validade fiscal">
    <div class="marca-dagua" aria-hidden="true">@for ($i = 0; $i < 7; $i++)<span>{{ $tarja }}</span>@endfor</div>

    <div class="tarja" role="note"><strong>{{ $tarja }}</strong><small>Gerado em ambiente de demonstração. Não foi enviado à prefeitura nem à Receita e não substitui documento fiscal.</small></div>

    <header class="topo">
        <div class="emitente">
            @if ($logo)<img src="{{ $logo }}" alt="Logo {{ $nomeEmpresa }}">@else<div class="monograma" aria-hidden="true">{{ mb_strtoupper(mb_substr($nomeEmpresa, 0, 1)) }}</div>@endif
            <div>
                <h1>{{ $nomeEmpresa }}</h1>
                <p>{{ $d['unidade'] }}</p>
                @if ($empresa->cnpj)<p>CNPJ {{ $empresa->cnpjFormatado() }}</p>@endif
            </div>
        </div>
        <div class="titulo">
            @php($ehNfe = ($d['tipo'] ?? 'nfse') === 'nfe')
            <b>{{ $ehNfe ? 'Nota de venda de peças (NF-e)' : 'Nota de serviço (NFS-e)' }} — simulação</b>
            <span class="num">Nº {{ $numero }}</span>
            <span>Modelo de demonstração inspirado no {{ $ehNfe ? 'DANFE' : 'DANFSe' }}</span>
        </div>
    </header>

    <section class="grade ids" aria-label="Identificação do documento">
        <div><span class="rotulo">Número</span><span class="mono">{{ $numero }}</span></div>
        <div><span class="rotulo">Série</span><span class="mono">SIM</span></div>
        <div><span class="rotulo">Data de emissão</span><span class="mono">{{ \App\Support\Fuso::exibir($emitido) }}</span></div>
        <div><span class="rotulo">Código de verificação</span><span class="mono">{{ $verificacao }}</span></div>
        <div><span class="rotulo">Protocolo (simulado)</span><span class="mono">{{ $protocolo }}</span></div>
    </section>

    <section class="partes">
        <div class="parte">
            <h2>Prestador</h2>
            <p class="nome">{{ $empresa->razao_social ?: $nomeEmpresa }}</p>
            @if ($empresa->nome_fantasia && $empresa->razao_social)<p class="sec">{{ $empresa->nome_fantasia }} · {{ $d['unidade'] }}</p>@else<p class="sec">{{ $d['unidade'] }}</p>@endif
            <p class="sec">CNPJ: {{ $empresa->cnpj ? $empresa->cnpjFormatado() : 'não informado' }}</p>
            @if ($empresa->enderecoTexto())<p class="sec">{{ $empresa->enderecoTexto() }}</p>@endif
            @if ($empresa->telefone || $empresa->email)<p class="sec">{{ collect([$empresa->telefone ? $empresa->telefoneFormatado() : null, $empresa->email])->filter()->join(' · ') }}</p>@endif
        </div>
        <div class="parte">
            <h2>Tomador</h2>
            <p class="nome">{{ $d['cliente'] }}</p>
            <p class="sec">CPF/CNPJ: não informado</p>
            <p class="sec">{{ ($d['cliente_endereco'] ?? '') ?: 'Endereço não informado' }}</p>
            @if (! empty($d['cliente_telefone']) || ! empty($d['cliente_email']))<p class="sec">{{ collect([$d['cliente_telefone'] ?? null, $d['cliente_email'] ?? null])->filter()->join(' · ') }}</p>@endif
        </div>
    </section>

    <section class="bloco">
        <h2>Discriminação dos serviços</h2>
        <table>
            <thead><tr><th scope="col">Descrição</th><th scope="col" class="num-col">Qtd.</th><th scope="col" class="num-col">Valor unitário</th><th scope="col" class="num-col">Total</th></tr></thead>
            <tbody>
            @foreach ($d['itens'] as $item)
                <tr>
                    <td>{{ $item['descricao'] }}</td>
                    <td class="num-col">{{ \App\Support\Dinheiro::quantidadeExibicao((string) $item['quantidade']) }}</td>
                    <td class="num-col">{{ isset($item['preco_unitario_centavos']) ? \App\Support\Dinheiro::formatar((int) $item['preco_unitario_centavos']) : '—' }}</td>
                    <td class="num-col">{{ \App\Support\Dinheiro::formatar((int) $item['total_centavos']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>

    <section class="totais">
        <div class="obs">
            <p><span class="rotulo">Referência</span>Ordem de serviço {{ $d['ordem'] }}</p>
            <p>Impostos e retenções não calculados: simulação sem regras tributárias.</p>
        </div>
        <div class="total"><span class="rotulo">Valor total do serviço</span><span class="valor">{{ \App\Support\Dinheiro::formatar((int) $d['valor_centavos']) }}</span></div>
    </section>

    <footer class="rodape">
        <span><strong>{{ $tarja }}.</strong> Documento gerado pelo simulador do sistema da oficina para treinamento e demonstração.</span>
        <span class="mono">Verificação {{ $verificacao }}</span>
    </footer>
</main>
</body>
</html>
