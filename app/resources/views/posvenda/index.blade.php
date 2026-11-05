@extends('layouts.app', ['titulo' => 'Pós-venda'])
@section('conteudo')
    @php($i = $indicadores)
    @php($periodo = \Carbon\Carbon::parse($de)->format('d/m/Y').' a '.\Carbon\Carbon::parse($ate)->format('d/m/Y'))
    <div class="page-head">
        <div><h1>Pós-venda</h1><p class="subtitle">Clientes atendidos de {{ $periodo }}. Ligue um a um: como foi o atendimento, se houve problema ou negociação fora do padrão.</p></div>
        <a class="button" href="{{ route('posvenda.exportar', request()->query()) }}">Exportar CSV</a>
    </div>
    <form method="GET" class="filters pv-filtros">
        @include('partials.campo', ['nome' => 'de', 'rotulo' => 'De', 'tipo' => 'date', 'valorAtual' => $de])
        @include('partials.campo', ['nome' => 'ate', 'rotulo' => 'Até', 'tipo' => 'date', 'valorAtual' => $ate])
        @if ($unidades->count() > 1)@include('partials.campo', ['nome' => 'unidade', 'rotulo' => 'Unidade', 'tipo' => 'select', 'opcoes' => ['' => 'Todas'] + $unidades->all(), 'valorAtual' => $filtros['unidade'] ?? ''])@endif
        @include('partials.campo', ['nome' => 'situacao', 'rotulo' => 'Situação do contato', 'tipo' => 'select', 'opcoes' => ['' => 'Todas'] + \App\Services\PosVenda::SITUACOES, 'valorAtual' => $filtros['situacao'] ?? ''])
        @if ($vendedores)@include('partials.campo', ['nome' => 'vendedor', 'rotulo' => 'Vendedor', 'tipo' => 'select', 'opcoes' => ['' => 'Todos'] + $vendedores, 'valorAtual' => $filtros['vendedor'] ?? ''])@endif
        <button class="button" type="submit">Filtrar</button>
    </form>

    <section class="summary pv-indicadores" aria-label="Indicadores de pós-venda">
        <article><p>Atendimentos entregues</p><strong>{{ $i['atendimentos'] }}</strong><small>No período</small></article>
        <article><p>Contatados</p><strong>{{ $i['contatados'] }} <span class="pv-pct">{{ $i['percentual_contatados'] }}%</span></strong><small>Cliente atendeu a ligação ou mensagem</small></article>
        <article><p>NPS do período</p><strong @class(['accent' => ($i['nps'] ?? 0) > 0])>{{ $i['nps'] === null ? '—' : $i['nps'] }}</strong><small>{{ $i['respostas_nps'] }} nota(s) · promotores (9–10) menos detratores (0–6)</small></article>
        <article><p>Problemas em aberto</p><strong>{{ $i['problemas_abertos'] }}</strong><small>Relatos sem resolução em Pendências</small></article>
        <article><p>Retornos para hoje</p><strong>{{ $i['retornos_hoje'] }}</strong><small>Agendados no último contato</small></article>
        <article><p>Retornos atrasados</p><strong>{{ $i['retornos_atrasados'] }}</strong><small>Data já passou</small></article>
    </section>

    @if ($retornos->isNotEmpty())
        <section class="notice pv-retornos" aria-label="Retornos pendentes">
            <div><strong>Retornos pendentes:</strong>
                @foreach ($retornos->take(12) as $r)
                    <a href="{{ route('posvenda.show', $r->ordem) }}">{{ $r->ordem->numero }}</a> ({{ $r->retorno_em->format('d/m') }})@if (! $loop->last), @endif
                @endforeach
                @if ($retornos->count() > 12) e mais {{ $retornos->count() - 12 }}.@endif
            </div>
        </section>
    @endif

    @if ($ordens->isEmpty())
        <p class="notice">Nenhum atendimento entregue com esses filtros. Ajuste o período ou a situação do contato.</p>
    @else
        <div class="table-wrap"><table class="data-table pv-tabela">
            <thead><tr><th>OS</th><th>Cliente</th><th>Veículo</th><th>Serviço</th>@if ($verValores)<th>Valor</th>@endif<th>Entregue em</th><th>Contato</th><th><span class="sr-only">Ações</span></th></tr></thead>
            <tbody>
            @foreach ($ordens as $o)
                @php($cs = $contatos->get($o->id, collect()))
                <tr>
                    <td data-label="OS"><a class="pv-os" href="{{ route('ordens.show', $o) }}" title="Abrir OS {{ $o->numero }}"><strong>{{ $o->numero }}</strong></a>@if ($unidades->count() > 1)<span class="subline">{{ $o->unidade?->nome }}</span>@endif</td>
                    <td data-label="Cliente">{{ $o->cliente?->nome }}@if ($verContato && $o->cliente?->telefone)<span class="subline">{{ \App\Support\Normaliza::telefoneExibicao($o->cliente->telefone) }}</span>@endif</td>
                    <td data-label="Veículo">{{ $o->veiculo?->modelo }}<span class="subline plate">{{ $o->veiculo?->placaFormatada() }}</span></td>
                    <td data-label="Serviço" class="pv-servico"><span class="pv-servico-texto">{{ \App\Services\AvisosColaborador::resumoServico($o, 90) }}</span></td>
                    @if ($verValores)<td data-label="Valor">{{ \App\Support\Dinheiro::formatar((int) ($o->orcamentoAprovado?->total_centavos ?? 0)) }}</td>@endif
                    <td data-label="Entregue em">{{ \App\Support\Fuso::exibir(\Carbon\CarbonImmutable::parse($o->entregue_em), 'd/m/Y') }}</td>
                    <td data-label="Contato">@include('posvenda._situacao', ['cs' => $cs])</td>
                    <td data-label="Ações" class="pv-acoes">
                        <a class="button primary" href="{{ route('posvenda.show', $o) }}">Registrar contato</a>
                        @if ($verContato && $o->cliente?->telefone)<a class="button" href="https://wa.me/55{{ $o->cliente->telefone }}" target="_blank" rel="noopener noreferrer" title="Abre o WhatsApp com o número do cliente; nada é enviado pelo sistema">WhatsApp</a>@endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $ordens->links() }}
    @endif
@endsection
