@extends('layouts.app', ['titulo' => 'Certificado digital'])
@section('conteudo')
    {{-- Etapa 2 fiscal (09/11/2026): o arquivo e a senha ficam criptografados no servidor e nunca voltam para a tela. --}}
    <div class="page-head"><div><h1>Certificado digital</h1>
        <p class="subtitle">O certificado A1 (arquivo .pfx ou .p12) assina as notas fiscais enviadas à Fazenda. Ele fica guardado criptografado e não aparece para ninguém depois de enviado.</p></div></div>
    <p class="notice">
        @switch($modo)
            @case('producao') Emissão real <strong>em produção</strong>: as notas têm validade fiscal. @break
            @case('homologacao') Emissão em <strong>homologação</strong> (ambiente de testes da Fazenda): as notas <strong>não</strong> têm validade fiscal. @break
            @default A emissão real está desligada neste ambiente; as notas são simuladas ou indisponíveis.
        @endswitch
    </p>

    @if ($cert)
        <section class="form-panel" aria-labelledby="cert-atual"><h2 id="cert-atual">Certificado guardado</h2>
            <dl class="detalhes">
                <dt>Titular</dt><dd>{{ $cert->titular }}</dd>
                <dt>CNPJ</dt><dd>{{ \App\Rules\Cnpj::exibicao($cert->documento) }}</dd>
                <dt>Válido até</dt><dd>{{ $cert->valido_ate->format('d/m/Y') }}
                    @if ($cert->vencido()) <strong class="alert erro">vencido</strong>
                    @elseif ($cert->valido_ate->lt(now()->addDays(30))) <strong>vence em {{ (int) now()->diffInDays($cert->valido_ate) }} dias</strong>
                    @endif</dd>
                <dt>Enviado em</dt><dd>{{ $cert->created_at->format('d/m/Y H:i') }}</dd>
            </dl>
            <form method="POST" action="{{ route('certificado.destroy') }}" onsubmit="return confirm('Remover o certificado? A emissão de notas para até um novo envio.')">
                @csrf @method('DELETE')
                <button class="button" type="submit">Remover certificado</button>
            </form>
        </section>
    @endif

    <form method="POST" action="{{ route('certificado.store') }}" enctype="multipart/form-data" class="form-panel" style="margin-top:24px">
        @csrf
        <section class="form-section"><h2>{{ $cert ? 'Trocar certificado' : 'Enviar certificado' }}</h2>
            <p class="hint">O sistema confere a senha, a validade e se o certificado é do CNPJ da empresa antes de guardar.</p>
            <div class="fields">
                @include('partials.campo', ['nome' => 'certificado', 'rotulo' => 'Arquivo do certificado (.pfx ou .p12)', 'tipo' => 'file', 'obrigatorio' => true, 'atributos' => ['accept' => '.pfx,.p12']])
                @include('partials.campo', ['nome' => 'senha', 'rotulo' => 'Senha do certificado', 'tipo' => 'password', 'obrigatorio' => true, 'autocomplete' => 'off'])
            </div>
        </section>
        <div class="form-actions"><button class="button primary" type="submit">Guardar certificado</button></div>
    </form>
@endsection
