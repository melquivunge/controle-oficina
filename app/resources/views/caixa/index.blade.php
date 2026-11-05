@extends('layouts.app', ['titulo' => auth()->user()->can('fechar_caixa') ? 'Caixa e fechamento' : 'Recebimentos'])
@section('conteudo')
    @php($u = auth()->user())
    {{-- Decisão 30/10 (opção A): quem não fecha o caixa vê movimento e a receber; fechamento e "esperado na gaveta" nem vão para o HTML. --}}
    @php($podeFechar = $u->can('fechar_caixa'))
    <div class="page-head"><div><h1>{{ $podeFechar ? 'Caixa e fechamento' : 'Recebimentos' }}</h1><p class="subtitle">Recebimentos do dia {{ \Carbon\Carbon::parse($data)->format('d/m/Y') }} · {{ $unidades->firstWhere('id', $unidadeId)?->nome }}</p></div></div>
    <form method="GET" class="filters">
        @if ($unidades->count() > 1)
            @include('partials.campo', ['nome' => 'unidade', 'rotulo' => 'Unidade', 'tipo' => 'select', 'opcoes' => $unidades->pluck('nome', 'id')->all(), 'valorAtual' => $unidadeId])
        @endif
        @include('partials.campo', ['nome' => 'data', 'rotulo' => 'Dia', 'tipo' => 'date', 'valorAtual' => $data])
        <button class="button" type="submit">Ver</button>
    </form>
    @php($abas = ['movimento' => 'Movimento', 'receber' => 'A receber ('.$aReceber->count().')'] + ($podeFechar ? ['fechamento' => 'Fechamento'] : []))
    @php($aba = array_key_exists(request('aba'), $abas) ? request('aba') : 'movimento')
    <nav class="abas" aria-label="Seções do caixa">
        @foreach ($abas as $chave => $rotulo)
            <a href="{{ request()->fullUrlWithQuery(['aba' => $chave]) }}" @if ($aba === $chave) aria-current="page" @endif>{{ $rotulo }}</a>
        @endforeach
    </nav>
    <div class="aba-painel" @if ($aba !== 'movimento') hidden @endif>
    @php($dinheiro = $dinheiroEsperado)
    @php($cartao = $recebimentos->where('meio', 'cartao_externo')->where('status', 'registrado')->sum('valor_centavos'))
    @php($pix = $recebimentos->where('meio', 'pix')->where('status', 'confirmado')->sum('valor_centavos'))
    <section class="summary" aria-label="Totais do dia">
        <article><p>Pix confirmado pelo banco</p><strong>{{ \App\Support\Dinheiro::formatar($pix) }}</strong><small>Confirmação automática</small></article>
        <article><p>Dinheiro (registro manual)</p><strong>{{ \App\Support\Dinheiro::formatar($dinheiro) }}</strong><small>@if (! $podeFechar)Registrado hoje, já descontadas devoluções em dinheiro @else Esperado na gaveta @endif @if ($podeFechar && $devolvidoDinheiro)· devolvido em dinheiro {{ \App\Support\Dinheiro::formatar($devolvidoDinheiro) }}@endif</small></article>
        <article><p>Cartão na maquininha</p><strong>{{ \App\Support\Dinheiro::formatar($cartao) }}</strong><small>Conferir com o extrato da maquininha</small></article>
    </section>
    @if ($recebimentos->where('status', 'em_revisao')->isNotEmpty())
        <p class="alert erro">Há {{ $recebimentos->where('status', 'em_revisao')->count() }} recebimento(s) em revisão neste dia. Eles não quitam ordens até serem resolvidos.</p>
    @endif
    @if ($recebimentos->isEmpty())
        <p class="muted">Nenhum recebimento neste dia.</p>
    @else
    <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Hora</th><th>Ordem</th><th>Meio</th><th>Valor</th><th>Situação</th><th>Registrado por</th></tr></thead>
        <tbody>
        @foreach ($recebimentos as $p)
            <tr class="linha-clicavel"><td data-label="Hora">{{ \App\Support\Fuso::exibir($p->recebido_em, 'H:i') }}</td>
                <td data-label="Ordem"><a class="linha-link" href="{{ route('ordens.show', ['ordem' => $p->ordem, 'aba' => 'pagamentos']) }}" aria-label="Abrir {{ $p->ordem->numero }}">{{ $p->ordem->numero }}</a></td>
                <td data-label="Meio">{{ $p->rotuloMeio() }}</td><td data-label="Valor">{{ \App\Support\Dinheiro::formatar($p->valor_centavos) }}</td>
                <td data-label="Situação">{{ $p->fechamento_id ? 'Conferido no fechamento' : $p->rotuloStatus() }}</td>
                <td data-label="Registrado por">{{ $p->registradoPor?->name ?? 'Banco (automático)' }}</td></tr>
        @endforeach
        </tbody>
    </table></div>
    @endif

    </div>
    <div class="aba-painel" @if ($aba !== 'receber') hidden @endif>
        @if ($aReceber->isEmpty())
            <p class="muted">Nenhuma ordem com saldo a receber nesta unidade.</p>
        @else
            <div class="table-wrap"><table class="data-table">
                <thead><tr><th>Ordem</th><th>Veículo</th><th>Cliente</th><th>Etapa</th><th>Saldo</th><th>Próxima parcela</th></tr></thead>
                <tbody>
                @foreach ($aReceber as [$o, $r, $comb])
                    @php($prox = $comb['proxima'] ?? null)
                    <tr @class(['linha-clicavel', 'parcela-vencida' => $prox?->situacao === 'vencida'])><td data-label="Ordem"><a class="linha-link" href="{{ route('ordens.show', ['ordem' => $o, 'aba' => 'pagamentos']) }}">{{ $o->numero }}</a></td>
                        <td data-label="Veículo">@if ($o->veiculo)<span class="plate">{{ $o->veiculo->placaFormatada() }}</span>@else<span class="item-avulso">{{ \Illuminate\Support\Str::limit($o->item_descricao, 40) }}</span>@endif</td>
                        <td data-label="Cliente">{{ $o->cliente->nome }}</td>
                        <td data-label="Etapa">{{ $o->status->rotulo() }}@if ($r['em_revisao']) <span class="tag extra">em revisão</span>@endif</td>
                        <td data-label="Saldo"><strong>{{ \App\Support\Dinheiro::formatar($r['saldo']) }}</strong></td>
                        <td data-label="Próxima parcela">@if ($prox){{ \App\Support\Dinheiro::formatar($prox->emAbertoCentavos()) }} em {{ $prox->vencimento->format('d/m/Y') }}
                            @if ($prox->situacao === 'vencida')<span class="tag off">Vencida</span>@endif
                            @if ($comb['vencido'] > $prox->emAbertoCentavos())<br><small>Total vencido {{ \App\Support\Dinheiro::formatar($comb['vencido']) }}</small>@endif
                            @if ($comb['desatualizado'])<br><small>Combinado não cobre o saldo atual</small>@endif
                            @else<span class="muted">Sem combinado</span>@endif</td></tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
    @if ($podeFechar)
    <div class="aba-painel" @if ($aba !== 'fechamento') hidden @endif>
    <section class="form-panel"><div class="form-section">
        <h2>Fechamento do dia</h2>
        <p class="hint" style="margin:8px 0 16px">Dinheiro esperado na gaveta: <strong>{{ \App\Support\Dinheiro::formatar($dinheiroEsperado) }}</strong>@if ($devolvidoDinheiro) (já descontados {{ \App\Support\Dinheiro::formatar($devolvidoDinheiro) }} devolvidos em dinheiro)@endif · Cartão na maquininha: {{ \App\Support\Dinheiro::formatar($cartao) }}</p>
        @if ($fechamento)
            <p>Fechado em {{ \App\Support\Fuso::exibir($fechamento->created_at) }}. Dinheiro no sistema {{ \App\Support\Dinheiro::formatar($fechamento->dinheiro_sistema_centavos) }}, contado {{ \App\Support\Dinheiro::formatar($fechamento->dinheiro_contado_centavos) }}
                (diferença {{ \App\Support\Dinheiro::formatar($fechamento->dinheiro_contado_centavos - $fechamento->dinheiro_sistema_centavos) }}). {{ $fechamento->observacao }}</p>
        @elseif ($u->can('fechar_caixa'))
            <form method="POST" action="{{ route('caixa.fechar') }}">
                @csrf
                <input type="hidden" name="unidade" value="{{ $unidadeId }}"><input type="hidden" name="data" value="{{ $data }}">
                <div class="fields">
                    @include('partials.campo', ['nome' => 'contado', 'rotulo' => 'Dinheiro contado no caixa (R$)', 'atributos' => ['inputmode' => 'decimal']])
                    @include('partials.campo', ['nome' => 'observacao', 'rotulo' => 'Observação (opcional)'])
                </div>
                <button class="button primary" type="submit">Fechar caixa do dia</button>
            </form>
        @else
            <p class="muted">O fechamento é feito pelo gerente.</p>
        @endif
    </div></section>
    </div>
    @endif
@endsection
