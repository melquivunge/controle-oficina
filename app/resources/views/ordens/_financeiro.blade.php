@php($fin = app(\App\Services\Financeiro::class)->resumo($ordem))
@php($cobrancas = $ordem->cobrancas()->get())
@php($pagamentos = $ordem->pagamentos()->with('registradoPor', 'devolucoes')->get())
@php($simulado = config('oficina.integracoes') === 'simulado' && config('oficina.pix.permite_simular_pagamento'))
@php($devolucoesResolvidas = \App\Models\ResolucaoPendencia::where('tipo', 'devolucao_falhou')->where('ordem_id', $ordem->id)->pluck('referencia_id')->map(fn ($id) => (int) $id)->all())
@php($combinadoSit = \App\Services\Combinados::podeVer($u) ? app(\App\Services\Combinados::class)->situacao($ordem, $fin) : null)
@if ($fin['aprovado'] > 0 || $pagamentos->isNotEmpty())
<section class="form-panel" style="margin-bottom:20px" id="pagamento">
    <div class="form-section">
        <h2>Pagamento</h2>
        @if (session('erro_cobranca'))<p class="alert erro" role="alert">{{ session('erro_cobranca') }}</p>@endif
        @foreach (['valor', 'valor_cobranca', 'valor_recebido', 'valor_devolucao', 'valor_simulado', 'cobranca', 'meio', 'entrega', 'pagamento', 'devolucao'] as $campoErro)
            @error($campoErro)<p class="alert erro" role="alert">{{ $message }}</p>@enderror
        @endforeach
        <div class="pg-resumo">
            <div><span>Aprovado</span><strong>{{ \App\Support\Dinheiro::formatar($fin['aprovado']) }}</strong></div>
            <div><span>Recebido</span><strong>{{ \App\Support\Dinheiro::formatar($fin['recebido'] - $fin['devolvido']) }}</strong>@if ($fin['devolvido'])<small>já descontado {{ \App\Support\Dinheiro::formatar($fin['devolvido']) }} devolvido</small>@endif</div>
            <div class="pg-saldo"><span>Saldo a receber</span><strong>{{ \App\Support\Dinheiro::formatar($fin['saldo']) }}</strong>@if ($fin['em_revisao'])<small>{{ \App\Support\Dinheiro::formatar($fin['em_revisao']) }} em revisão (ainda não quita)</small>@endif</div>
        </div>
        @if ($combinadoSit)
            <div class="pg-proximas">
                <h3>Parcelas combinadas</h3>
                <ul>
                    @foreach ($combinadoSit['parcelas'] as $parc)
                        <li><span>{{ $parc->vencimento->format('d/m/Y') }}</span><strong>{{ \App\Support\Dinheiro::formatar((int) $parc->valor_centavos) }}</strong>@if ($parc->rotuloMeio())<small>{{ $parc->rotuloMeio() }}</small>@endif<span @class(['tag', 'off' => $parc->situacao === 'vencida', 'extra' => $parc->situacao === 'parcial'])>{{ $parc->rotuloSituacao() }}</span></li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

    @if ($fin['saldo'] > 0 && in_array($ordem->status, \App\Enums\StatusOrdem::recebiveis(), true))
        @include('ordens._como_pagar')
    @endif

    @if ($combinadoSit)
    <div class="form-section">
        @include('ordens._combinado')
    </div>
    @endif

    <div class="form-section" id="historico-pagamentos">
        <h2>Cobranças e recebimentos</h2>
        @if ($cobrancas->isEmpty() && $pagamentos->isEmpty())<p class="muted">Nenhuma cobrança ou recebimento ainda.</p>@endif

        @foreach ($cobrancas as $c)
            <article class="versao">
                <header><strong>Cobrança Pix · {{ \App\Support\Dinheiro::formatar($c->valor_centavos) }}</strong><span @class(['tag', 'off' => in_array($c->status, ['cancelada', 'expirada', 'falha'])])>{{ $c->rotuloStatus() }}</span></header>
                @if ($c->status === 'pendente')
                    <p class="hint">Conta oficial: {{ $c->recebedor->descricao }} · válida até {{ \App\Support\Fuso::exibir($c->expira_em) }}. A confirmação é automática quando o banco notificar; comprovante enviado pelo cliente não quita.</p>
                    <p class="code-box">{{ $c->copia_e_cola }}</p>
                    @if ($simulado && ! app()->isProduction())
                        <details><summary>Ambiente de simulação: simular pagamento do cliente</summary>
                            <form method="POST" action="{{ route('pagamentos.simular', [$ordem, $c]) }}" class="inline-form">
                                @csrf
                                @include('partials.campo', ['nome' => 'valor_simulado', 'rotulo' => 'Valor pago (vazio = valor cobrado)', 'id' => 'valor-sim-'.$c->id])
                                <button class="button" type="submit">Simular pagamento (sem dinheiro real)</button>
                            </form>
                        </details>
                    @endif
                @endif
            </article>
        @endforeach

        @if ($pagamentos->isNotEmpty())
            <h3 style="margin-top:20px">Recebimentos e devoluções</h3>
            @foreach ($pagamentos as $p)
                <article class="versao">
                    <header><strong>{{ \App\Support\Dinheiro::formatar($p->valor_centavos) }} · {{ $p->rotuloMeio() }}</strong><span @class(['tag', 'extra' => $p->status !== 'confirmado'])>{{ $p->rotuloStatus() }}</span></header>
                    @if ($p->comprovante_caminho)<p><a class="link" href="{{ route('pagamentos.comprovante', [$ordem, $p]) }}" target="_blank" rel="noopener">Ver comprovante</a></p>@endif
                    <p class="hint">{{ \App\Support\Fuso::exibir($p->recebido_em) }}{{ $p->registradoPor ? ' · registrado por '.$p->registradoPor->name : '' }}{{ $p->motivo_revisao ? ' · '.$p->motivo_revisao : '' }}</p>
                    @foreach ($p->devolucoes as $d)
                        <div class="inline-form">
                            <p class="hint">Devolução {{ \App\Support\Dinheiro::formatar($d->valor_centavos) }} — {{ $d->motivo }}
                                <span @class(['tag', 'extra' => $d->status === 'processando', 'off' => $d->status === 'falhou'])>{{ $d->rotuloStatus() }}</span>
                                @if ($d->status === 'falhou')<br><strong>Falha:</strong> {{ $d->mensagem }} Nenhum valor saiu da conta.@endif
                                @if ($d->status === 'processando')<br>Ainda não abate o recebido; aguardando o provedor.@endif
                            </p>
                            @if ($d->status === 'falhou' && $u->pode('registrar_devolucao') && ! in_array($d->id, $devolucoesResolvidas, true))
                                <form method="POST" action="{{ route('devolucoes.tentar', [$ordem, $d]) }}">@csrf
                                    <button class="button" type="submit">Tentar de novo</button></form>
                            @endif
                        </div>
                    @endforeach
                    @if ($p->status === 'em_revisao')
                        @can('resolver_divergencias')
                            <details><summary>Aceitar após conferência</summary>
                                <form method="POST" action="{{ route('pagamentos.aceitar', [$ordem, $p]) }}">@csrf
                                    @include('partials.campo', ['nome' => 'motivo', 'rotulo' => 'O que foi conferido', 'id' => 'aceitar-'.$p->id])
                                    <button class="button" type="submit">Aceitar recebimento</button></form>
                            </details>
                        @endcan
                    @endif
                    @can('registrar_devolucao')
                        <details><summary>Registrar devolução</summary>
                            <form method="POST" action="{{ route('pagamentos.devolver', [$ordem, $p]) }}">@csrf
                                @include('partials.campo', ['nome' => 'valor_devolucao', 'rotulo' => 'Valor devolvido', 'id' => 'dev-valor-'.$p->id, 'atributos' => ['inputmode' => 'decimal']])
                                @include('partials.campo', ['nome' => 'motivo', 'rotulo' => 'Motivo', 'id' => 'dev-motivo-'.$p->id])
                                <button class="button" type="submit">Registrar devolução</button></form>
                        </details>
                    @endcan
                </article>
            @endforeach
        @endif
    </div>

    <div class="form-section" id="entrega">
        <h2>Entrega</h2>
        @if ($ordem->entrega)
            <p>Entregue em {{ \App\Support\Fuso::exibir($ordem->entrega->entregue_em) }} por {{ $ordem->entrega->entreguePor->name }}.
                @if ($ordem->entrega->excecao)<span class="tag extra">Liberado sem quitação</span> Saldo na entrega: {{ \App\Support\Dinheiro::formatar($ordem->entrega->saldo_centavos) }} — {{ $ordem->entrega->excecao_motivo }}@endif@if ($ordem->entrega->combinado_id)<span class="tag">Entregue com pagamento combinado</span> Saldo na entrega: {{ \App\Support\Dinheiro::formatar($ordem->entrega->saldo_centavos) }}@endif</p>
        @elseif ($ordem->status === \App\Enums\StatusOrdem::ProntoParaEntrega)
            @can('entregar')
                <form method="POST" action="{{ route('ordens.entregar', $ordem) }}">
                    @csrf
                    @if ($fin['saldo'] === 0)
                        <p>Pagamento quitado. Confira o veículo com o cliente antes de liberar.</p>
                        <button class="button primary" type="submit">Registrar entrega ao cliente</button>
                    @elseif ($combinadoSit && $combinadoSit['cobre'])
                        <p>Saldo de {{ \App\Support\Dinheiro::formatar($fin['saldo']) }} coberto pelo combinado de pagamento (próximo vencimento {{ $combinadoSit['proxima']?->vencimento?->format('d/m/Y') }}). A entrega fica registrada com o combinado, sem exceção.</p>
                        <button class="button primary" type="submit">Registrar entrega com pagamento combinado</button>
                    @elseif ($u->pode('liberar_sem_quitacao'))
                        <p class="notice">Saldo em aberto de {{ \App\Support\Dinheiro::formatar($fin['saldo']) }}. Liberar sem quitação gera um alerta de exceção.</p>
                        @include('partials.campo', ['nome' => 'motivo', 'rotulo' => 'Motivo da liberação sem quitação', 'id' => 'motivo-entrega'])
                        <button class="button" type="submit">Liberar sem quitação</button>
                    @else
                        <p class="notice">Saldo em aberto de {{ \App\Support\Dinheiro::formatar($fin['saldo']) }}. A entrega só pode ser registrada após a quitação ou com autorização do gerente.</p>
                    @endif
                </form>
            @endcan
        @else
            <p class="muted">Disponível quando o serviço estiver pronto para entrega.</p>
        @endif
    </div>
</section>
@endif
