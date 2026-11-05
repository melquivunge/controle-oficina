@php($usuario = auth()->user())
@php(['total' => $totalNotificacoes, 'whatsapp' => $whatsappSemResposta, 'itens' => $notificacoes] = app(\App\Services\Notificacoes::class)->abertas($usuario))
@php($empresaAtual = \App\Models\Empresa::atual())
@php($aparencia = \App\Support\Aparencia::resolver($usuario, $empresaAtual))
<!doctype html>
<html lang="pt-BR" data-tema="{{ $aparencia['modo'] }}" @if ($aparencia['estilo']) style="{{ $aparencia['estilo'] }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="referrer" content="no-referrer">
    <title>{{ $titulo ?? $empresaAtual->nomeSistema() }} · {{ $empresaAtual->nomeSistema() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app">
@include('partials.banner-demo')
<a class="skip" href="#conteudo">Ir para o conteúdo</a>
<div class="menu-fundo" data-menu-fechar hidden></div>
<aside class="sidebar" id="menu-lateral" aria-label="Menu">
    <div class="sidebar-topo">
        <a class="brand" href="{{ route('painel') }}">
            @include('partials.marca', ['empresa' => $empresaAtual])
            <span>{{ $empresaAtual->nomeSistema() }}<small>{{ $usuario->visaoGlobal() ? 'Todas as unidades' : (count($usuario->unidadeIds()) > 1 ? count($usuario->unidadeIds()).' unidades' : $usuario->unidade?->nome) }}</small></span>
        </a>
        <button type="button" class="menu-x" aria-label="Fechar menu" data-menu-fechar>
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
    </div>
    <form class="sidebar-busca" method="GET" action="{{ route('ordens.index') }}" role="search">
        <label class="sidebar-busca-rotulo" for="busca-menu">Busca rápida</label>
        @include('partials.icone', ['i' => 'busca'])
        <input id="busca-menu" name="busca" type="search" aria-label="Buscar placa, OS ou cliente" placeholder="Buscar placa, OS ou cliente" autocomplete="off" enterkeyhint="search" data-busca-global>
        <kbd aria-hidden="true">⌘K</kbd>
    </form>
    @php($pode = fn ($rota, $cond = true) => $cond && \Illuminate\Support\Facades\Route::has($rota))
    @php($grupos = [
        'Principal' => [
            ['painel', 'Painel', 'painel', $usuario->papel !== \App\Enums\Papel::Mecanico],
            ['painel.mecanico', 'Painel', 'painel', $usuario->papel === \App\Enums\Papel::Mecanico],
            ['entradas.nova', 'Nova entrada', 'mais', $usuario->can('registrar_entrada')],
            ['ordens.index', 'Ordens de serviço', 'ordens', $usuario->papel !== \App\Enums\Papel::Mecanico],
            ['orcamentos.index', 'Orçamentos', 'orcamento', $usuario->can('ver_valores')],
            ['fila', 'Fila do mecânico', 'chave', $usuario->can('executar')],
            ['caixa.index', $usuario->can('fechar_caixa') ? 'Caixa e fechamento' : 'Recebimentos', 'caixa', $usuario->can('receber_manual')],
            ['conferencia.index', 'Conferência física', 'conferencia', $usuario->can('conferir')],
        ],
        'Atendimento' => [
            ['whatsapp.index', 'Central de WhatsApp', 'whatsapp', \App\Models\WhatsappConversa::atende($usuario) || \App\Models\WhatsappConversa::fazTriagem($usuario)],
            ['posvenda.index', 'Pós-venda', 'clientes', $usuario->can('pos_venda')],
        ],
        'Cadastros' => [
            ['clientes.index', 'Clientes', 'clientes', $usuario->can('registrar_entrada')],
            ['veiculos.index', 'Veículos', 'veiculo', $usuario->can('registrar_entrada')],
        ],
        'Gestão' => [
            ['indicadores', 'Relatórios por unidade', 'operacao', $usuario->can('ver_auditoria')],
            ['aprovacoes.index', 'Aprovações de desconto', 'auditoria', \App\Services\AprovacoesInternas::podeAprovar($usuario)],
            ['pendencias.index', 'Pendências e mensagens', 'sino', $usuario->can('resolver_divergencias')],
            ['auditoria.index', 'Auditoria', 'auditoria', $usuario->can('ver_auditoria')],
        ],
        'Configurações' => [
            ['empresa.edit', 'Identidade da empresa', 'unidade', $usuario->can('configurar')],
            ['usuarios.index', 'Usuários e permissões', 'usuarios', $usuario->can('configurar')],
            ['unidades.index', 'Unidades', 'unidade', $usuario->can('configurar')],
            ['recebedores.index', 'Contas de recebimento', 'conta', $usuario->can('configurar')],
            ['whatsapp.numeros.index', 'Números de WhatsApp', 'whatsapp', $usuario->can('configurar')],
            ['catalogo.index', 'Catálogo de serviços', 'orcamento', \App\Services\Catalogo::podeGerir($usuario)],
            ['feriados.index', 'Feriados locais', 'conferencia', $usuario->can('gerir_feriados') || $usuario->can('configurar')],
            ['integracoes.index', 'Integrações', 'integracao', $usuario->can('configurar')],
        ],
    ])
    <nav class="nav" aria-label="Menu principal">
        @foreach ($grupos as $grupo => $links)
            @php($links = array_filter($links, fn ($l) => $pode($l[0], $l[3])))
            @if ($links)
                <p class="nav-label">{{ $grupo }}</p>
                @foreach ($links as [$rota, $rotulo, $icone])
                    @php($ativo = $rota === 'whatsapp.index' ? request()->routeIs('whatsapp.*') && ! request()->routeIs('whatsapp.numeros.*') : request()->routeIs(substr_count($rota, '.') > 1 ? \Illuminate\Support\Str::beforeLast($rota, '.').'*' : \Illuminate\Support\Str::before($rota, '.').'*'))
                    <a href="{{ route($rota) }}" @class(['active' => $ativo]) @if($ativo) aria-current="page" @endif>@include('partials.icone', ['i' => $icone]){{ $rotulo }}
                        @if ($rota === 'pendencias.index' && $totalNotificacoes - $whatsappSemResposta)<span class="count">{{ $totalNotificacoes - $whatsappSemResposta }}</span>@endif
                        @if ($rota === 'whatsapp.index' && $whatsappSemResposta)<span class="count" title="Sem resposta">{{ $whatsappSemResposta }}</span>@endif</a>
                @endforeach
            @endif
        @endforeach
    </nav>
    <div class="sidebar-rodape">
        <a href="{{ route('conta') }}" @class(['active' => request()->routeIs('conta*')])>@include('partials.icone', ['i' => 'cadeado'])Minha conta e segurança</a>
        <form method="POST" action="{{ route('sair') }}">@csrf<button type="submit">@include('partials.icone', ['i' => 'sair'])Sair</button></form>
    </div>
</aside>
<div class="shell">
    <header class="topbar">
        <button type="button" class="hamburger" aria-controls="menu-lateral" aria-expanded="false" aria-label="Abrir menu" data-menu-abrir>
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
        </button>
        <nav class="trail" aria-label="Você está em">
            <a href="{{ route('painel') }}">Início</a><span aria-hidden="true">›</span><span aria-current="page">{{ $titulo ?? '' }}</span>
        </nav>
        <div class="topbar-acoes">
            <details class="pop" data-pop>
                <summary class="icone-botao" aria-label="Notificações{{ $totalNotificacoes ? ': '.$totalNotificacoes.' abertas' : '' }}">
                    @include('partials.icone', ['i' => 'sino'])@if ($totalNotificacoes)<span class="badge-num">{{ $totalNotificacoes > 99 ? '99+' : $totalNotificacoes }}</span>@endif
                </summary>
                <div class="pop-painel pop-notificacoes">
                    <header><strong>Notificações</strong><small>Situações para revisar, não acusações</small></header>
                    @forelse ($notificacoes as $n)
                        <a href="{{ $n['url'] }}" class="notif"><span @class(['ponto', 'grave' => $n['grave']]) aria-hidden="true"></span>
                            <span><strong>{{ $n['titulo'] }}</strong><small>{{ $n['texto'] }}</small><small>{{ \App\Support\Fuso::exibir($n['quando']) }}</small></span></a>
                    @empty
                        <p class="muted pop-vazio">Nada pendente nas suas unidades.</p>
                    @endforelse
                    @if (\Illuminate\Support\Facades\Route::has('pendencias.index') && $usuario->can('resolver_divergencias'))
                        <a class="pop-rodape" href="{{ route('pendencias.index') }}">Ver todas as pendências →</a>
                    @endif
                </div>
            </details>
            @can('configurar')
                <a class="icone-botao" href="{{ route('empresa.edit') }}" aria-label="Configurações da empresa">@include('partials.icone', ['i' => 'engrenagem'])</a>
            @endcan
            <details class="pop" data-pop>
                <summary class="usuario-botao" aria-label="Menu da conta de {{ $usuario->name }}">
                    @include('partials.avatar', ['u' => $usuario])
                    <span class="usuario-nome"><strong>{{ $usuario->name }}</strong><small>{{ $usuario->cargo ?: $usuario->papel->rotulo() }}</small></span>
                    @include('partials.icone', ['i' => 'seta-baixo'])
                </summary>
                <div class="pop-painel pop-usuario">
                    <header class="pop-perfil">@include('partials.avatar', ['u' => $usuario, 'classe' => 'avatar-g'])<span><strong>{{ $usuario->name }}</strong><small>{{ $usuario->email }}</small><small>{{ $usuario->cargo ?: $usuario->papel->rotulo() }} · {{ $usuario->visaoGlobal() ? 'Todas as unidades' : (count($usuario->unidadeIds()) > 1 ? count($usuario->unidadeIds()).' unidades' : $usuario->unidade?->nome) }}</small></span></header>
                    <a href="{{ route('conta') }}">@include('partials.icone', ['i' => 'clientes'])Meu perfil</a>
                    <a href="{{ route('conta') }}#senha">@include('partials.icone', ['i' => 'cadeado'])Alterar senha</a>
                    @can('configurar')<a href="{{ route('empresa.edit') }}">@include('partials.icone', ['i' => 'engrenagem'])Configurações da empresa</a>@endcan
                    <form method="POST" action="{{ route('sair') }}">@csrf<button type="submit">@include('partials.icone', ['i' => 'sair'])Sair</button></form>
                </div>
            </details>
        </div>
    </header>
    <main id="conteudo" class="content">
        @if (session('sucesso'))
            <p class="alert sucesso" role="status">{{ session('sucesso') }}</p>
        @endif
        @include('partials.chave-2fa')
        @if ($errors->any())
            <div class="alert erro" role="alert">Revise os campos destacados: {{ $errors->first() }}</div>
        @endif
        {{ $slot ?? '' }}
        @yield('conteudo')
        <p class="footnote">Horários de Brasília.</p>
    </main>
</div>
</body>
</html>
