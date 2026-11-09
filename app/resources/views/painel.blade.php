@extends('layouts.app', ['titulo' => 'Painel'])
@section('conteudo')
    @php($usuario = auth()->user())
    @php($fin = $usuario->can('ver_auditoria'))
    @php($f = fn ($c) => \App\Support\Dinheiro::formatar($c))
    @php($noPatio = $porStatus->sum())
    @php($filtrosUrl = fn (array $extra) => route('painel', array_filter(['unidade' => $unidadeId, 'responsavel' => $responsavelId] + $extra)))
    <div class="page-head">
        <div>
            <h1>Olá, {{ \Illuminate\Support\Str::before($usuario->name.' ', ' ') }}</h1>
            <p class="subtitle">{{ \Carbon\Carbon::parse($de)->format('d/m/Y') }} a {{ \Carbon\Carbon::parse($ate)->format('d/m/Y') }} · {{ $unidades->firstWhere('id', $unidadeId)?->nome ?? ($usuario->visaoGlobal() ? 'Todas as unidades' : ($unidades->count() > 1 ? $unidades->pluck('nome')->join(', ') : $usuario->unidade?->nome)) }}@if ($responsavelId) · Entradas registradas por {{ $responsaveis->firstWhere('id', $responsavelId)?->name }}@endif</p>
        </div>
        <div class="page-acoes">
            @if ($fin)<form method="POST" action="{{ route('painel.modo') }}" class="inline-form">@csrf @method('PUT')<input type="hidden" name="simples" value="1">
                <button class="button" type="submit" title="Resumo em linguagem simples: o que está acontecendo nas lojas e o que fazer">@include('partials.icone', ['i' => 'painel'])Versão simples</button></form>@endif
            @if ($fin)<a class="button" href="{{ route('painel.exportar', request()->only('unidade', 'periodo', 'de', 'ate', 'responsavel')) }}">@include('partials.icone', ['i' => 'download'])Exportar CSV</a>
                <a class="button" href="{{ route('painel.exportar', request()->only('unidade', 'periodo', 'de', 'ate', 'responsavel') + ['formato' => 'xlsx']) }}">@include('partials.icone', ['i' => 'download'])Exportar Excel</a>@endif
            @if ($usuario->papel === \App\Enums\Papel::Supervisor && \App\Http\Controllers\FeriadoUnidadeController::podeGerir($usuario))<a class="button" href="{{ route('feriados.index') }}">Feriados das unidades</a>@endif
            @can('registrar_entrada')<a class="button primary" href="{{ route('entradas.nova') }}"><span class="plus">＋</span> Nova entrada</a>@endcan
        </div>
    </div>

    <form method="GET" class="painel-filtro" aria-label="Filtros do painel">
        <nav class="segmento" aria-label="Período">
            @foreach (\App\Http\Controllers\PainelController::PERIODOS as $p => $rotulo)
                <a href="{{ $filtrosUrl(['periodo' => $p]) }}" @if ($periodo === (string) $p) aria-current="true" @endif>{{ $rotulo }}</a>
            @endforeach
        </nav>
        @include('partials.campo', ['nome' => 'de', 'rotulo' => 'De', 'tipo' => 'date', 'valorAtual' => $de])
        @include('partials.campo', ['nome' => 'ate', 'rotulo' => 'Até', 'tipo' => 'date', 'valorAtual' => $ate])
        @if ($unidades->count() > 1){{-- proprietário e supervisor multiunidade --}}
            @include('partials.campo', ['nome' => 'unidade', 'rotulo' => 'Unidade', 'tipo' => 'select', 'opcoes' => ['' => 'Todas as unidades'] + $unidades->pluck('nome', 'id')->all(), 'valorAtual' => $unidadeId])
        @endif
        @if ($responsaveis->isNotEmpty())
            @include('partials.campo', ['nome' => 'responsavel', 'rotulo' => 'Responsável (entrada)', 'tipo' => 'select', 'opcoes' => ['' => 'Todos'] + $responsaveis->pluck('name', 'id')->all(), 'valorAtual' => $responsavelId])
        @endif
        <button class="button" type="submit">Aplicar</button>
    </form>
    @if ($responsavelId)
        <p class="hint">Filtro por responsável: números consideram só as ordens cuja entrada foi registrada por {{ $responsaveis->firstWhere('id', $responsavelId)?->name }} (não quem recebeu, cobrou ou entregou).</p>
    @endif

    @if ($unidades->isEmpty())
        <p class="notice">Nenhuma unidade ativa. @can('configurar')<a href="{{ route('unidades.index') }}">Cadastre as unidades</a> para começar.@endcan</p>
    @endif

    <section class="kpis" aria-label="Indicadores do período">
        @php($cards = [
            ['Entradas', array_sum($serie['entradas']), $serie['entradas'], 'azul', $anterior['entradas'] ?? null, 'veículos recebidos'],
            ['Entregas', array_sum($serie['entregas']), $serie['entregas'], 'verde', $anterior['saidas'] ?? null, 'veículos entregues'],
        ])
        @if ($fin)
            @php($cards[] = ['Aprovado', $f($total['aprovado']), $serie['aprovado'], 'roxo', $anterior['aprovado'], $total['aprovacoes'].' orçamentos aprovados', $total['aprovado']])
            @php($cards[] = ['Recebido (caixa)', $f($total['recebido']), $serie['recebido'], 'verde', $anterior['recebido'], $total['devolvido'] ? 'após devoluções de '.$f($total['devolvido']) : 'Pix confirmado, dinheiro e cartão', $total['recebido']])
        @else
            @php($cards[] = ['No pátio agora', $noPatio, null, 'azul', null, 'ordens abertas'])
            @php($cards[] = ['Prontos para entrega', $porStatus['pronto_para_entrega'] ?? 0, null, 'verde', null, 'conferir antes de liberar'])
        @endif
        @foreach ($cards as $c)
            <article class="kpi">
                <h2>{{ $c[0] }}</h2>
                <div class="kpi-corpo">
                    <strong>{{ $c[1] }}</strong>
                    @if ($c[2])@include('partials.sparkline', ['valores' => $c[2], 'cor' => $c[3]])@endif
                </div>
                @if ($c[4] !== null)@include('partials.variacao', ['atual' => $c[6] ?? $c[1], 'anterior' => $c[4]])@endif
                <small>{{ $c[5] }}</small>
            </article>
        @endforeach
    </section>

    <div class="painel-grade">
        <section class="cartao cartao-grafico" data-grafico>
            {{-- Gráfico interativo: dinheiro (recebido/aprovado) só para quem vê indicadores financeiros E valores; para os demais
                 essas séries nem entram nos dados da página. Sem JS: barras são links e a métrica padrão continua visível. --}}
            @php($g = \App\Services\SeriesPainel::grafico($serie, $fin && $usuario->can('ver_valores'), $de, $ate))
            @php($m = $g['metricas'][$g['padrao']])
            @php($maxV = max($m['valores'] ?: [0]))
            @php($maxBarra = max($maxV, 1))
            @php($pico = $maxV > 0 ? array_search($maxV, $m['valores'], true) : null)
            <header class="cartao-topo">
                <div><h2 data-grafico-titulo>{{ $m['titulo'] }}</h2>
                    <p class="subtitle">Por {{ $g['escala'] }} · maior valor destacado</p></div>
                <strong class="cartao-total" data-grafico-total>{{ $m['total'] }}</strong>
            </header>
            <div class="segmento grafico-metricas" role="group" aria-label="Métrica do gráfico" data-grafico-metricas hidden>
                @foreach ($g['metricas'] as $chave => $info)
                    <button type="button" data-metrica="{{ $chave }}" aria-pressed="{{ $chave === $g['padrao'] ? 'true' : 'false' }}">{{ $info['rotulo'] }}</button>
                @endforeach
            </div>
            <div class="barras" role="group" data-grafico-barras
                aria-label="{{ $m['rotulo'] }} por {{ $g['escala'] }}@if ($pico !== null); maior valor {{ $m['curtos'][$pico] }} em {{ $g['grupos'][$pico]['titulo'] }}@endif. Cada barra abre as ordens do período.">
                @foreach ($g['grupos'] as $i => $gr)
                    <a @class(['barra', 'pico' => $i === $pico]) data-i="{{ $i }}"
                        href="{{ route('ordens.index', array_filter(['unidade' => $unidadeId, 'responsavel' => $responsavelId, 'de' => $gr['de'], 'ate' => $gr['ate'], 'data' => $m['data']])) }}"
                        aria-label="{{ $gr['titulo'] }}: {{ $m['textos'][$i] }}@if ($g['padrao'] !== 'entradas'), {{ $g['entradas'][$i] }} {{ $g['entradas'][$i] === 1 ? 'entrada' : 'entradas' }}@endif. Abrir ordens">
                        @if ($i === $pico)<span class="barra-valor">{{ $m['curtos'][$i] }}</span>@endif
                        <span class="barra-coluna" style="height:{{ max(2, round($m['valores'][$i] * 100 / $maxBarra)) }}%"></span>
                        <span class="barra-rotulo">{{ $gr['rotulo'] }}</span>
                    </a>
                @endforeach
            </div>
            <div class="grafico-dica" data-grafico-dica aria-hidden="true" hidden></div>
            <p class="sr-only" aria-live="polite" data-grafico-aviso></p>
            <script type="application/json" data-grafico-dados>@json($g)</script>
        </section>

        <aside class="painel-lado">
            @if ($fin)
                <section class="cartao">
                    <h2>Recebido por forma</h2>
                    @php($meios = ['pix' => 'Pix integrado', 'dinheiro' => 'Dinheiro', 'cartao_externo' => 'Cartão (máquina externa)', 'pix_manual' => 'Pix com comprovante'])
                    @php($somaMeios = max(array_sum($serie['por_meio']), 1))
                    <ul class="meios">
                        @foreach ($meios as $m => $rot)
                            @php($v = $serie['por_meio'][$m] ?? 0)
                            <li><span>{{ $rot }}</span><b>{{ $f($v) }}</b><span class="trilho"><span class="trilho-{{ $m }}" style="width:{{ round($v * 100 / $somaMeios) }}%"></span></span></li>
                        @endforeach
                    </ul>
                </section>
                <section class="cartao cartao-mini">
                    <div><p>Saldo em aberto</p><strong>{{ $f($total['pendente']) }}</strong><small>Aprovado e ainda não pago (hoje)</small></div>
                    <div><p>Ticket médio aprovado</p><strong>{{ $f($total['aprovacoes'] ? intdiv($total['aprovado'], $total['aprovacoes']) : 0) }}</strong><small>Aprovado ÷ orçamentos aprovados</small></div>
                    <div><p>Orçado (enviado)</p><strong>{{ $f($total['orcado']) }}</strong><small>{{ $total['recusas'] }} recusa(s) no período</small></div>
                </section>
            @else
                <section class="cartao"><h2>Chegou um veículo?</h2><p class="subtitle" style="margin:8px 0 14px">Registre os dados essenciais e o motivo da visita.</p>
                    @can('registrar_entrada')<a class="button primary" href="{{ route('entradas.nova') }}">Registrar entrada →</a>@endcan</section>
            @endif
        </aside>
    </div>

    {{-- Três colunas fixas (cada cartão com a própria altura, sem buracos de linha). Coluna vazia some pelo CSS. --}}
    <div class="painel-grade painel-grade-3">
        <div class="painel-coluna">
        <section @class(['cartao', 'cartao-prazo', 'alto' => $atrasadas])>
            <header class="cartao-topo"><h2>Entregas atrasadas</h2><a class="link" href="{{ route('ordens.index', array_filter(['atrasadas' => 1, 'unidade' => $unidadeId])) }}">Ver lista →</a></header>
            <p class="prazo-numero"><strong>{{ $atrasadas }}</strong> <span>{{ $atrasadas === 1 ? 'ordem passou' : 'ordens passaram' }} da data prevista e não {{ $atrasadas === 1 ? 'está pronta' : 'estão prontas' }}</span></p>
            <p class="hint">Data prevista em dias úteis, definida pela gerência. Informe o cliente sobre o novo prazo.</p>
        </section>
        <section class="cartao">
            <header class="cartao-topo"><h2>OS por etapa (agora)</h2><a class="link" href="{{ route('ordens.index', array_filter(['unidade' => $unidadeId])) }}">{{ $noPatio }} no pátio →</a></header>
            @php($maxEtapa = max($porStatus->max() ?? 0, 1))
            <ul class="etapas-barras">
                @foreach (\App\Enums\StatusOrdem::abertas() as $s)
                    <li><a href="{{ route('ordens.index', array_filter(['status' => $s->value, 'unidade' => $unidadeId])) }}"><span>{{ $s->rotulo() }}</span><b>{{ $porStatus[$s->value] ?? 0 }}</b>
                        <span class="trilho"><span style="width:{{ round(($porStatus[$s->value] ?? 0) * 100 / $maxEtapa) }}%"></span></span></a></li>
                @endforeach
            </ul>
        </section>
        </div>
        <div class="painel-coluna">
        @isset($whatsapp)
            <section class="cartao">
                <header class="cartao-topo"><h2>WhatsApp (agora)</h2><a class="link" href="{{ route('whatsapp.index') }}">Central →</a></header>
                <ul class="atencao">
                    <li @class(['alto' => $whatsapp['sem_resposta']])><span>Sem resposta</span><b>{{ $whatsapp['sem_resposta'] }}</b><small>Cliente esperando além do tempo combinado</small></li>
                    <li><span>Conversas abertas</span><b>{{ $whatsapp['abertas'] }}</b><small>{{ $whatsapp['minhas'] }} sob sua responsabilidade</small></li>
                    @if (\App\Models\WhatsappConversa::fazTriagem($usuario))<li @class(['alto' => $whatsapp['triagem']])><span>Na triagem central</span><b>{{ $whatsapp['triagem'] }}</b><small>Aguardando encaminhamento para uma unidade</small></li>@endif
                    <li><span>1ª resposta (média)</span><b>{{ $whatsapp['tempo_medio_primeira_resposta_min'] !== null ? number_format($whatsapp['tempo_medio_primeira_resposta_min'], 0, ',', '.').' min' : '—' }}</b><small>Últimos 30 dias</small></li>
                </ul>
            </section>
        @endisset
        @if ($fin)
            <section class="cartao">
                <header class="cartao-topo"><h2>Pontos de atenção</h2>@if (\Illuminate\Support\Facades\Route::has('pendencias.index'))<a class="link" href="{{ route('pendencias.index') }}">Pendências →</a>@endif</header>
                <ul class="atencao">
                    <li @class(['alto' => $total['divergencias_abertas']])><span>Divergências abertas</span><b>{{ $total['divergencias_abertas'] }}</b><small>Conferência física</small></li>
                    <li @class(['alto' => $total['falhas']])><span>Falhas e revisões</span><b>{{ $total['falhas'] }}</b><small>Mensagens, cobranças, devoluções, fiscal, Pix em revisão</small></li>
                    <li @class(['alto' => $total['liberacoes']])><span>Entregas sem quitação</span><b>{{ $total['liberacoes'] }}</b><small>{{ $total['liberado_sem_quitacao'] ? $f($total['liberado_sem_quitacao']).' liberados' : 'Nenhum valor liberado' }}</small></li>
                    <li><span>Descontos</span><b>{{ $total['descontos_qtd'] }}</b><small>{{ $f($total['descontos']) }}</small></li>
                    <li><span>Cancelamentos</span><b>{{ $total['cancelamentos'] }}</b><small>No período</small></li>
                </ul>
                <p class="hint">Alertas indicam necessidade de revisão, não provam irregularidade.</p>
            </section>
        @endif
        </div>
        <div class="painel-coluna">
        {{-- Vendas por dia da semana (período e filtros atuais). Quem não vê valores vê só entradas. --}}
        @php($sem = $semana)
        @php($porDia = $sem['metrica'] === 'recebido' ? 'media' : 'entradas')
        @php($maxDia = max(max(array_column($sem['dias'], $porDia) ?: [0]), 1))
        <section class="cartao cartao-semana" aria-labelledby="titulo-semana">
            <header class="cartao-topo"><div><h2 id="titulo-semana">{{ $fin ? 'Vendas por dia da semana' : 'Entradas por dia da semana' }}</h2>
                <p class="subtitle">{{ $fin ? 'Média recebida por dia · entradas no período' : 'Entradas no período' }}</p></div>
                @if ($fin)<a class="link" href="{{ route('painel.exportar', request()->only('unidade', 'periodo', 'de', 'ate', 'responsavel') + ['visao' => 'dia_semana']) }}">CSV →</a>@endif</header>
            <ul class="semana-lista">
                @foreach ($sem['dias'] as $n => $l)
                    <li @class(['melhor' => $n === $sem['melhor'], 'pior' => $n === $sem['pior']])>
                        <span class="semana-dia">{{ $l['rotulo'] }}@if ($n === $sem['melhor'])<em class="tag semana-tag">Melhor</em>@elseif ($n === $sem['pior'])<em class="tag semana-tag">Pior</em>@endif</span>
                        <b>{{ $fin ? $f($l['recebido']) : $l['entradas'] }}</b>
                        <span class="trilho"><span style="width:{{ round($l[$porDia] * 100 / $maxDia) }}%"></span></span>
                        <small>@if ($fin){{ $f($l['media']) }}/dia · {{ $l['entradas'] }} {{ $l['entradas'] === 1 ? 'entrada' : 'entradas' }} · @endif{{ $l['sem_venda'] }} de {{ $l['ocorrencias'] }} sem {{ $fin ? 'venda' : 'entrada' }}</small>
                    </li>
                @endforeach
            </ul>
            <p class="hint"><strong>{{ $sem['sem_venda'] }}</strong> de {{ $sem['total_dias'] }} {{ $sem['total_dias'] === 1 ? 'dia' : 'dias' }} sem {{ $fin ? 'venda (nada recebido)' : 'entrada' }} no período.@if ($sem['melhor'] === null) Sem diferença entre os dias para destacar.@endif</p>
        </section>
        @if ($fin)
            @if (count($linhas) > 1)
                <section class="cartao">
                    <header class="cartao-topo"><h2>Por unidade</h2><a class="link" href="{{ route('indicadores', ['de' => $de, 'ate' => $ate]) }}">Relatório →</a></header>
                    <ul class="unidades-lista">
                        @foreach ($unidades as $un)
                            @continue(! isset($linhas[$un->id]))
                            @php($l = $linhas[$un->id])
                            <li><a href="{{ $filtrosUrl(['unidade' => $un->id, 'periodo' => $periodo, 'de' => $periodo ? null : $de, 'ate' => $periodo ? null : $ate]) }}">
                                <span><strong>{{ $un->nome }}</strong><small>{{ $l['entradas'] }} entradas · {{ array_sum($l['por_etapa']) }} no pátio</small></span><b>{{ $f($l['recebido']) }}</b></a></li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @endif
        </div>
    </div>

    <section class="cartao">
        <header class="cartao-topo"><div><h2>Atendimentos abertos</h2><p class="subtitle">{{ $ordens->count() }} mais recentes</p></div><a class="link" href="{{ route('ordens.index', array_filter(['unidade' => $unidadeId])) }}">Ver todas →</a></header>
        @include('ordens._lista', ['vazio' => 'Nenhum veículo na oficina agora. Quando um cliente chegar, registre a entrada.'])
    </section>
@endsection
