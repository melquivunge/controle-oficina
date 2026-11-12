@php($empresaAtual = \App\Models\Empresa::atual())
@php($aparencia = \App\Support\Aparencia::resolver($empresaAtual))
<!doctype html>
<html lang="pt-BR" data-tema="{{ $aparencia['modo'] }}" @if ($aparencia['estilo']) style="{{ $aparencia['estilo'] }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="referrer" content="no-referrer">
    <title>{{ $titulo ?? 'Entrar' }} · {{ $empresaAtual->nomeSistema() }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="app guest">
@include('partials.banner-demo')
<div class="guest-grade">
    <aside class="guest-marca" aria-hidden="true">
        @include('partials.marca', ['empresa' => $empresaAtual])
        <p class="guest-titulo">{{ $empresaAtual->nomeSistema() }}</p>
        <p class="guest-texto">{{ $empresaAtual->descricao ?: 'Entrada, orçamento, execução, caixa e entrega de cada veículo, com o histórico de quem fez o quê.' }}</p>
        <ul><li>Orçado, aprovado e recebido sempre separados</li><li>Cada pessoa com a própria conta</li><li>Auditoria de todas as ações</li></ul>
    </aside>
    <main id="conteudo" class="auth-wrap">
        <div class="auth-card">
            <span class="brand auth-brand">@include('partials.marca', ['empresa' => $empresaAtual]){{ $empresaAtual->nomeSistema() }}</span>
            @yield('conteudo')
        </div>
    </main>
</div>
</body>
</html>
