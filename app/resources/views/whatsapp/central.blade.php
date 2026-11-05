@extends('layouts.app', ['titulo' => $conversa ? 'WhatsApp · '.$conversa->nomeExibicao() : 'Central de WhatsApp'])
@section('conteudo')
    @php($qs = request()->only(['situacao', 'unidade', 'numero', 'responsavel', 'busca']))
    <div @class(['page-head', 'wa-cabecalho', 'wa-oculto-celular' => $conversa])>
        <div>
            <h1>Central de WhatsApp</h1>
            <p class="subtitle">Tudo o que chega nos números oficiais da empresa, com responsável, situação e tempo de resposta.</p>
        </div>
        @if ($podeSimular && $numeros->isNotEmpty())
            <details class="wa-simular">
                <summary class="button">Simular mensagem recebida</summary>
                <form method="POST" action="{{ route('whatsapp.simular') }}" class="wa-simular-form">@csrf
                    <p class="hint">Simulação: nada chega de fora nem é enviado a ninguém. Disponível só fora de produção.</p>
                    @include('partials.campo', ['nome' => 'numero_id', 'rotulo' => 'Número que recebe', 'id' => 'sim-numero', 'tipo' => 'select',
                        'opcoes' => $numeros->mapWithKeys(fn ($n) => [$n->id => $n->nome.' · '.$n->rotuloEscopo()])->all()])
                    @include('partials.campo', ['nome' => 'telefone', 'rotulo' => 'Telefone do contato (DDD + número)', 'id' => 'sim-telefone', 'tipo' => 'tel', 'obrigatorio' => true, 'atributos' => ['inputmode' => 'tel', 'placeholder' => '61 99999-0001']])
                    @include('partials.campo', ['nome' => 'nome', 'rotulo' => 'Nome no perfil (opcional)', 'id' => 'sim-nome'])
                    @include('partials.campo', ['nome' => 'tipo', 'rotulo' => 'Tipo', 'id' => 'sim-tipo', 'tipo' => 'select',
                        'opcoes' => ['texto' => 'Texto', 'imagem' => 'Imagem', 'audio' => 'Áudio', 'documento' => 'Documento (PDF)', 'localizacao' => 'Localização']])
                    <div class="field"><label for="sim-texto">Texto ou legenda</label><textarea id="sim-texto" name="texto" maxlength="1000" rows="2">{{ old('texto') }}</textarea></div>
                    <button class="button primary" type="submit">Simular recebimento</button>
                </form>
            </details>
        @endif
    </div>

    <section @class(['kpis', 'wa-kpis', 'wa-oculto-celular' => $conversa]) aria-label="Indicadores da Central">
        <article class="kpi"><h2>Abertas</h2><div class="kpi-corpo"><strong>{{ $indicadores['abertas'] }}</strong></div><small>não resolvidas</small></article>
        <article @class(['kpi', 'wa-kpi-alerta' => $indicadores['sem_resposta'] > 0])><h2>Sem resposta</h2><div class="kpi-corpo"><strong>{{ $indicadores['sem_resposta'] }}</strong></div><small>além do limite do número</small></article>
        @if ($veTriagem)
            <article class="kpi"><h2>Em triagem</h2><div class="kpi-corpo"><strong>{{ $indicadores['triagem'] }}</strong></div><small>número central, sem unidade</small></article>
        @else
            <article class="kpi"><h2>Comigo</h2><div class="kpi-corpo"><strong>{{ $indicadores['minhas'] }}</strong></div><small>abertas sob sua responsabilidade</small></article>
        @endif
        <article class="kpi"><h2>1ª resposta</h2><div class="kpi-corpo"><strong>{{ $indicadores['tempo_medio_primeira_resposta_min'] === null ? '—' : number_format($indicadores['tempo_medio_primeira_resposta_min'], 1, ',', '.').' min' }}</strong></div><small>tempo médio, últimos 30 dias</small></article>
    </section>

    <form method="GET" action="{{ route('whatsapp.index') }}" @class(['filters', 'wa-filtros', 'wa-oculto-celular' => $conversa]) role="search">
        <div class="field wa-filtro-busca"><label for="wa-busca">Buscar</label><input id="wa-busca" name="busca" type="search" value="{{ $filtros['busca'] }}" placeholder="Nome ou telefone" enterkeyhint="search"></div>
        <div class="field"><label for="wa-situacao">Situação</label>
            <select id="wa-situacao" name="situacao">
                @foreach (['abertas' => 'Abertas', 'sem_resposta' => 'Sem resposta (atrasadas)'] + \App\Models\WhatsappConversa::SITUACOES + ['todas' => 'Todas'] as $v => $t)
                    <option value="{{ $v }}" @selected($filtros['situacao'] === $v)>{{ $t }}</option>
                @endforeach
            </select></div>
        <div class="field"><label for="wa-unidade">Unidade</label>
            <select id="wa-unidade" name="unidade">
                <option value="">Todas que posso ver</option>
                @if ($veTriagem)<option value="triagem" @selected($filtros['unidade'] === 'triagem')>Triagem (número central)</option>@endif
                @foreach ($unidadesFiltro as $u)<option value="{{ $u->id }}" @selected($filtros['unidade'] === (string) $u->id)>{{ $u->nome }}</option>@endforeach
            </select></div>
        <div class="field"><label for="wa-numero">Número</label>
            <select id="wa-numero" name="numero">
                <option value="">Todos</option>
                @foreach ($numeros as $n)<option value="{{ $n->id }}" @selected($filtros['numero'] === (string) $n->id)>{{ $n->nome }}</option>@endforeach
            </select></div>
        <div class="field"><label for="wa-responsavel">Responsável</label>
            <select id="wa-responsavel" name="responsavel">
                <option value="">Qualquer</option>
                <option value="eu" @selected($filtros['responsavel'] === 'eu')>Comigo</option>
                <option value="sem" @selected($filtros['responsavel'] === 'sem')>Sem responsável</option>
            </select></div>
        <button class="button" type="submit">Filtrar</button>
    </form>

    <div @class(['wa-layout', 'com-conversa' => $conversa])>
        <nav class="wa-lista" aria-label="Conversas">
            <p class="wa-lista-total">{{ $conversas->total() }} {{ $conversas->total() === 1 ? 'conversa' : 'conversas' }}</p>
            @if ($conversas->isEmpty())
                <div class="vazio-cartao">@include('partials.icone', ['i' => 'busca'])<p>{{ $numeros->isEmpty() ? 'Nenhum número oficial disponível para você. O proprietário cadastra os números em Configurações.' : 'Nenhuma conversa com esses filtros.' }}</p></div>
            @else
                <ul>
                    @foreach ($conversas as $c)
                        @php($atrasada = isset($atrasadas[$c->id]))
                        <li><a href="{{ route('whatsapp.show', [$c] + $qs) }}" @class(['wa-item', 'atrasada' => $atrasada]) @if ($conversa?->id === $c->id) aria-current="page" @endif>
                            <span class="ficha-avatar tom-{{ $c->id % 6 }}" aria-hidden="true">{{ $c->iniciais() }}</span>
                            <span class="wa-item-corpo">
                                <span class="wa-item-linha"><strong>{{ $c->nomeExibicao() }}</strong><time datetime="{{ $c->ultima_mensagem_em?->toIso8601String() }}">{{ $c->ultima_mensagem_em ? (\App\Support\Fuso::exibir($c->ultima_mensagem_em, 'd/m/Y') === \App\Support\Fuso::exibir(now(), 'd/m/Y') ? \App\Support\Fuso::exibir($c->ultima_mensagem_em, 'H:i') : \App\Support\Fuso::exibir($c->ultima_mensagem_em, 'd/m')) : '' }}</time></span>
                                <small class="wa-item-previa">@if ($c->ultimaMensagem?->direcao === 'saida')<span class="muted">{{ $c->ultimaMensagem->origem === 'celular' ? 'Celular:' : 'Você:' }}</span> @endif{{ \Illuminate\Support\Str::limit($c->ultimaMensagem?->resumo() ?? '', 70) }}</small>
                                <span class="wa-item-tags">
                                    @if ($atrasada)<span class="tag off">Sem resposta há {{ \App\Support\Duracao::curta((int) $c->aguardando_desde->diffInMinutes(now())) }}</span>@endif
                                    <span class="tag">{{ $c->rotuloSituacao() }}</span>
                                    <span @class(['tag', 'extra' => $c->emTriagem()])>{{ $c->emTriagem() ? 'Triagem' : $c->unidade?->nome }}</span>
                                    <span class="muted wa-item-tel">{{ $c->telefonePara($usuario) }}</span>
                                </span>
                            </span>
                        </a></li>
                    @endforeach
                </ul>
                @include('partials.paginacao', ['paginator' => $conversas])
            @endif
        </nav>

        @if ($conversa)
            @include('whatsapp._conversa')
        @else
            <section class="wa-conversa wa-conversa-vazia">
                <div class="vazio-cartao">@include('partials.icone', ['i' => 'conta'])<p>Escolha uma conversa à esquerda para ver o histórico e responder.</p></div>
            </section>
        @endif
    </div>
@endsection
