@extends('layouts.app', ['titulo' => 'Painel'])
@section('conteudo')
    {{-- Painel simples (02/11/2026): respostas diretas para quem não quer gráficos. Só gerência/proprietário (ver_auditoria). --}}
    @php($usuario = auth()->user())
    @php($f = fn ($c) => \App\Support\Dinheiro::formatar($c))
    @php($n = fn (int $q, string $um, string $varios) => $q.' '.($q === 1 ? $um : $varios))
    @php($tarefas = array_filter([
        $soma('atrasadas') ? ['alto', $n($soma('atrasadas'), 'carro passou', 'carros passaram').' da data prometida', 'Ligue para o cliente e combine um novo prazo.', route('ordens.index', ['atrasadas' => 1])] : null,
        $soma('prontos') ? ['', $n($soma('prontos'), 'carro está pronto', 'carros estão prontos').' esperando o cliente', 'Avise o cliente para buscar e receber o pagamento.', route('ordens.index', ['status' => 'pronto_para_entrega'])] : null,
        $soma('aguardando') ? ['', $n($soma('aguardando'), 'orçamento espera', 'orçamentos esperam').' resposta do cliente', 'Sem resposta, o serviço não começa. Vale um lembrete.', route('ordens.index', ['status' => 'aguardando_aprovacao'])] : null,
        $pendencias ? ['alto', $n($pendencias, 'situação precisa', 'situações precisam').' da sua revisão', 'Falhas de envio, entregas sem pagamento, diferenças na conferência…', route('pendencias.index')] : null,
    ]))
    <div class="page-head">
        <div>
            <h1>Olá, {{ \Illuminate\Support\Str::before($usuario->name.' ', ' ') }}</h1>
            <p class="subtitle">Resumo de hoje, {{ \Carbon\Carbon::parse($hoje)->format('d/m/Y') }} · {{ $lojas->count() === 1 ? $lojas->first()['unidade']->nome : $n($lojas->count(), 'loja', 'lojas') }}</p>
        </div>
        <div class="page-acoes">
            <form method="POST" action="{{ route('painel.modo') }}" class="inline-form">@csrf @method('PUT')<input type="hidden" name="simples" value="0">
                <button class="button" type="submit">@include('partials.icone', ['i' => 'painel'])Painel completo</button></form>
            @can('registrar_entrada')<a class="button primary" href="{{ route('entradas.nova') }}"><span class="plus">＋</span> Nova entrada</a>@endcan
        </div>
    </div>

    @if ($lojas->isEmpty())
        <p class="notice">Nenhuma unidade ativa. @can('configurar')<a href="{{ route('unidades.index') }}">Cadastre as unidades</a> para começar.@endcan</p>
    @endif

    <section class="kpis" aria-label="Respostas rápidas">
        <article class="kpi"><h2>Quantos carros estão na oficina?</h2><div class="kpi-corpo"><strong>{{ $soma('na_oficina') }}</strong></div>
            <small>Hoje entraram {{ $soma('entradas') }} e saíram {{ $soma('saidas') }}</small></article>
        <article class="kpi"><h2>Quantos estão prontos?</h2><div class="kpi-corpo"><strong>{{ $soma('prontos') }}</strong></div>
            <small>Esperando o cliente buscar</small></article>
        <article class="kpi"><h2>Quanto entrou de dinheiro hoje?</h2><div class="kpi-corpo"><strong>{{ $f($soma('recebido')) }}</strong></div>
            <small>Pix confirmado, dinheiro e cartão</small></article>
        <article class="kpi"><h2>Quanto falta receber?</h2><div class="kpi-corpo"><strong>{{ $f($soma('pendente')) }}</strong></div>
            <small>Serviços aprovados ainda não pagos</small></article>
    </section>

    <section class="cartao simples-tarefas" aria-labelledby="titulo-tarefas">
        <h2 id="titulo-tarefas">O que precisa ser feito</h2>
        @if ($tarefas)
            <ul class="atencao">
                @foreach ($tarefas as [$nivel, $titulo, $dica, $url])
                    <li @class(['alto' => $nivel])><a href="{{ $url }}"><span>@if ($nivel)<span class="status off">Urgente</span> @endif{{ $titulo }}</span><b aria-hidden="true">→</b><small>{{ $dica }}</small></a></li>
                @endforeach
            </ul>
        @else
            <p class="simples-ok"><span class="status ready">Tudo em dia</span> Nada pendente agora. Nenhum carro atrasado e nada para revisar.</p>
        @endif
    </section>

    @if ($lojas->count() > 0)
        <section aria-labelledby="titulo-lojas">
            <h2 id="titulo-lojas" class="simples-secao">Como está cada loja</h2>
            <div class="simples-lojas">
                @foreach ($lojas as $l)
                    @php([$classe, $situacao] = $l['atrasadas'] || $l['divergencias'] ? ['off', 'Precisa de atenção'] : ($l['prontos'] || $l['aguardando'] ? ['wait', 'Tem coisa esperando'] : ['ready', 'Tudo certo']))
                    <article class="cartao">
                        <header class="cartao-topo"><h3>{{ $l['unidade']->nome }}</h3><span class="status {{ $classe }}">{{ $situacao }}</span></header>
                        <ul class="atencao">
                            <li><span>Carros na loja agora</span><b>{{ $l['na_oficina'] }}</b></li>
                            <li><span>Prontos para o cliente buscar</span><b>{{ $l['prontos'] }}</b></li>
                            <li @class(['alto' => $l['atrasadas']])><span>Atrasados</span><b>{{ $l['atrasadas'] }}</b></li>
                            <li><span>Esperando o cliente aprovar</span><b>{{ $l['aguardando'] }}</b></li>
                            <li><span>Recebido hoje</span><b>{{ $f($l['recebido']) }}</b></li>
                        </ul>
                        <a class="link" href="{{ route('ordens.index', ['unidade' => $l['unidade']->id]) }}">Ver carros desta loja →</a>
                    </article>
                @endforeach
            </div>
        </section>
    @endif
    <p class="hint">Avisos indicam o que revisar; não provam irregularidade. Para gráficos, períodos e exportação, use o painel completo.</p>
@endsection
