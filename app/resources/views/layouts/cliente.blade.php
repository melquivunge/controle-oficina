@php($empresaAtual = \App\Models\Empresa::atual())
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $titulo ?? 'Seu atendimento' }} · {{ $ordem->unidade->nome ?? 'Oficina' }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="app portal-cliente">
@include('partials.banner-demo')
<header class="portal-topo">@include('partials.marca', ['empresa' => $empresaAtual])<span>{{ $ordem->unidade->nome ?? 'Oficina' }}<small>Acompanhamento do seu atendimento</small></span></header>
<main id="conteudo" class="portal">
    @yield('conteudo')
    <p class="footnote">Este link é pessoal e dá acesso somente a este atendimento. Horários de Brasília.</p>
</main>
</body>
</html>
