@extends('layouts.app', ['titulo' => 'Dados fiscais'])
@section('conteudo')
    {{-- 09/11/2026: dois tipos de nota. Valores iniciais = os usados pelo sistema antigo (XML das notas emitidas). --}}
    <div class="page-head"><div><h1>Dados fiscais</h1>
        <p class="subtitle">A oficina emite dois tipos de nota: <strong>NFS-e</strong> para os serviços e <strong>NF-e</strong> para a venda de peças. Cada item do orçamento é marcado como serviço ou peça e vai para a nota certa.</p></div></div>
    @unless ($salvo)
        <p class="alert aviso" role="status">Valores preenchidos com o que o sistema antigo usava nas notas emitidas. Peça ao contador para conferir e salve.</p>
    @endunless
    <p class="notice">A emissão real usa o <a href="{{ route('certificado.edit') }}">certificado digital</a> da empresa. Os campos "próximo número" valem só para produção: devem continuar a numeração do sistema antigo (o contador confirma). Em homologação a numeração começa em 1.</p>
    <form method="POST" action="{{ route('fiscal.config.update') }}" class="form-panel">
        @csrf @method('PUT')
        <section class="form-section"><h2>Empresa</h2><div class="fields">
            @include('partials.campo', ['nome' => 'regime', 'rotulo' => \App\Support\DadosFiscais::ROTULOS['regime'], 'tipo' => 'select', 'opcoes' => ['simples' => 'Simples Nacional (ME/EPP)', 'normal' => 'Regime normal'], 'valorAtual' => $dados['regime']])
            @foreach (['inscricao_municipal', 'inscricao_estadual', 'municipio_ibge'] as $c)
                @include('partials.campo', ['nome' => $c, 'rotulo' => \App\Support\DadosFiscais::ROTULOS[$c], 'valorAtual' => $dados[$c], 'atributos' => ['inputmode' => 'numeric']])
            @endforeach
        </div></section>
        <section class="form-section"><h2>Nota de serviço (NFS-e Nacional — Fazenda do DF)</h2>
            <p class="hint">Padrão do sistema antigo: item 14.01 (manutenção e conservação de veículos), ISS 2%.</p><div class="fields">
            @foreach (['nfse_serie', 'nfse_proximo_numero', 'nfse_c_trib_nac', 'nfse_c_trib_mun', 'nfse_c_nbs', 'nfse_aliquota_iss'] as $c)
                @include('partials.campo', ['nome' => $c, 'rotulo' => \App\Support\DadosFiscais::ROTULOS[$c], 'valorAtual' => $dados[$c], 'atributos' => ['inputmode' => 'decimal']])
            @endforeach
        </div></section>
        <section class="form-section"><h2>Nota de venda de peças (NF-e 4.00 — SEFAZ)</h2>
            <p class="hint">Padrão do sistema antigo: CFOP 5102 (dentro do DF) e 6108 (fora, consumidor final), CSOSN 102, NCM 8708.70.90 (rodas). O NCM de cada peça pode ser definido no catálogo.</p><div class="fields">
            @foreach (['nfe_serie', 'nfe_proximo_numero', 'nfe_cfop_dentro', 'nfe_cfop_fora', 'nfe_csosn', 'nfe_ncm_padrao', 'nfe_natureza'] as $c)
                @include('partials.campo', ['nome' => $c, 'rotulo' => \App\Support\DadosFiscais::ROTULOS[$c], 'valorAtual' => $dados[$c]])
            @endforeach
        </div></section>
        <div class="form-footer"><button class="button primary" type="submit">Salvar dados fiscais</button></div>
    </form>
@endsection
