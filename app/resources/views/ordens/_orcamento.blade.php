@php($u = auth()->user())
@php($verValores = $u->can('ver_valores'))
@php($versoes = $ordem->orcamentos()->with('itens', 'aprovacao.registradoPor', 'autor')->get())
@php($rascunho = $versoes->firstWhere('status', 'rascunho'))
<section class="form-panel" style="margin-bottom:20px" id="orcamento">
    {{-- Bloco A: versões, preços, totais e descontos só para quem vê valores; o colaborador vê os serviços aprovados em Execução/Resumo. --}}
    @if ($verValores)
    <div class="form-section">
        <h2>Orçamento</h2>
        @if (session('link_cliente'))
            <div class="alert sucesso" role="status">
                <strong>Link para o cliente</strong> (mostrado só agora; o envio por mensagem oficial será automático quando o canal estiver configurado):
                <p class="code-box">{{ session('link_cliente') }}</p>
            </div>
        @endif
        @forelse ($versoes->where('status', '!=', 'rascunho') as $v)
            <article class="versao">
                <header><strong>Versão {{ $v->versao }} · {{ \App\Support\Dinheiro::formatar($v->total_centavos) }}</strong><span @class(['tag', 'off' => in_array($v->status, ['recusado', 'substituido'])])>{{ $v->rotuloStatus() }}</span></header>
                <ul style="margin:0 0 8px;padding-left:18px">
                    @foreach ($v->itens as $i)
                        <li>{{ \App\Support\Dinheiro::quantidadeExibicao($i->quantidade) }} × {{ $i->descricao }} — {{ \App\Support\Dinheiro::formatar($i->total_centavos) }}
                            @if ($i->preco_tabela_centavos !== null)<span class="muted">(tabela {{ \App\Support\Dinheiro::formatar($i->preco_tabela_centavos) }}@if ($i->descontoPercentual()) · −{{ str_replace('.', ',', $i->descontoPercentual()) }}%@endif)</span>@endif
                            @if ($i->extra)<span class="tag extra">extra</span>@endif
                            @if ($v->status === 'aprovado' && $i->concluido_em)<span class="item-feito">✓ concluído</span>@endif</li>
                    @endforeach
                </ul>
                @if ($v->desconto_centavos)<p class="hint">Desconto {{ \App\Support\Dinheiro::formatar($v->desconto_centavos) }} — {{ $v->desconto_motivo }}</p>@endif
                <p class="hint">Enviada {{ \App\Support\Fuso::exibir($v->enviado_em) }}@if ($v->aprovacao) · {{ $v->aprovacao->decisao === 'aprovado' ? 'Aprovada' : 'Recusada' }} {{ \App\Support\Fuso::exibir($v->aprovacao->decidido_em) }} {{ $v->aprovacao->canal === 'link_cliente' ? 'pelo cliente (link)' : 'presencialmente, registrado por '.$v->aprovacao->registradoPor?->name }}{{ $v->aprovacao->observacao ? ' — '.$v->aprovacao->observacao : '' }}@endif</p>
                @if ($v->status === 'enviado')
                    @can('acompanharCliente', $ordem)
                        <details><summary>Registrar decisão informada pessoalmente ou por telefone</summary>
                            <form method="POST" action="{{ route('orcamentos.presencial', [$ordem, $v]) }}">
                                @csrf
                                @include('partials.campo', ['nome' => 'decisao', 'rotulo' => 'Decisão do cliente', 'tipo' => 'select', 'opcoes' => ['aprovado' => 'Aprovou', 'recusado' => 'Recusou'], 'id' => 'decisao-'.$v->id])
                                @include('partials.campo', ['nome' => 'observacao', 'rotulo' => 'Como o cliente decidiu (ex.: ligação às 10h, falou com Ana)', 'id' => 'obs-'.$v->id])
                                @can('definirPrazo', $ordem)
                                    @include('partials.campo', ['nome' => 'prazo_dias', 'rotulo' => 'Prazo de entrega em dias úteis (se aprovou; opcional)', 'tipo' => 'number', 'id' => 'prazo-dias-'.$v->id, 'atributos' => ['min' => 1, 'max' => config('oficina.prazo_entrega_max_dias_uteis', 120), 'inputmode' => 'numeric']])
                                @endcan
                                <button class="button" type="submit">Registrar decisão</button>
                            </form>
                        </details>
                    @endcan
                @endif
            </article>
        @empty
            <p class="muted">Nenhuma versão enviada ainda.</p>
        @endforelse

        @can('orcar', $ordem)
            @if (in_array($ordem->status, \App\Services\Orcamentos::STATUS_EDITAVEIS, true))
                <h3 style="margin-top:20px">{{ $rascunho ? "Rascunho da versão {$rascunho->versao}" : ($versoes->isEmpty() ? 'Montar orçamento' : 'Nova versão (ajuste ou serviço extra)') }}</h3>
                @if ($ordem->status === \App\Enums\StatusOrdem::EmExecucao)
                    <p class="hint">Serviços novos entram como extra e só podem ser executados após nova aprovação do cliente.</p>
                @endif
                <livewire:orcamento-editor :ordem="$ordem" :key="'editor-'.$ordem->id.'-'.($rascunho?->updated_at?->timestamp ?? 0)" />
                @php($liberacao = $rascunho ? app(\App\Services\AprovacoesInternas::class)->situacao($rascunho) : null)
                @if ($rascunho && $liberacao['exige'])
                    {{-- Catálogo (31/10/2026): desconto acima do limite exige aprovação interna antes do envio (conferido também no servidor). --}}
                    @php($pedido = $liberacao['pedido'])
                    <div @class(['aprovacao-interna', 'alert', 'sucesso' => $liberacao['liberado'], 'erro' => $pedido?->status === 'recusada', 'aviso' => ! $liberacao['liberado'] && $pedido?->status !== 'recusada']) role="status">
                        <strong>{{ $pedido ? $pedido->rotuloStatus() : 'Desconto acima do limite' }}</strong>
                        — maior desconto {{ str_replace('.', ',', $liberacao['avaliacao']['maior']) }}%.
                        @if ($pedido?->status === 'pendente')
                            Pedido de {{ $pedido->solicitante?->name }} em {{ \App\Support\Fuso::exibir($pedido->solicitado_em) }}. O envio ao cliente fica bloqueado até a decisão.
                            @if (\App\Services\AprovacoesInternas::podeAprovar($u) && ($pedido->solicitado_por !== $u->id || $u->papel === \App\Enums\Papel::Proprietario))
                                <a href="{{ route('aprovacoes.index') }}">Decidir em Aprovações</a>
                            @endif
                        @elseif ($pedido && in_array($pedido->status, ['aprovada', 'recusada'], true))
                            {{ $pedido->status === 'aprovada' ? 'Aprovado' : 'Recusado' }} por {{ $pedido->decisor?->name }}: {{ $pedido->justificativa }}
                            @if ($pedido->status === 'recusada') Ajuste o desconto e salve o rascunho de novo. @endif
                        @else
                            Salve o rascunho para abrir o pedido de aprovação.
                        @endif
                    </div>
                @endif
                @if ($rascunho && $liberacao['liberado'])
                    <form method="POST" action="{{ route('orcamentos.enviar', [$ordem, $rascunho]) }}" style="margin-top:12px">
                        @csrf
                        @include('partials.campo', ['nome' => 'prazo_dias', 'rotulo' => 'Prazo de entrega em dias úteis (opcional)', 'tipo' => 'number', 'id' => 'prazo-dias-envio', 'valorAtual' => '', 'ajuda' => 'Seg–sex, sem feriados, a partir de hoje. A recepção informa a data ao cliente.', 'atributos' => ['min' => 1, 'max' => config('oficina.prazo_entrega_max_dias_uteis', 120), 'inputmode' => 'numeric']])
                        <button class="button primary" type="submit">Enviar versão {{ $rascunho->versao }} ao cliente ({{ \App\Support\Dinheiro::formatar($rascunho->total_centavos) }})</button>
                    </form>
                @endif
            @elseif ($ordem->status === \App\Enums\StatusOrdem::Entrada)
                <p class="muted">Inicie o diagnóstico para montar o orçamento.</p>
            @endif
        @endcan
        @can('acompanharCliente', $ordem)
            @if ($versoes->where('status', '!=', 'rascunho')->isNotEmpty())
                <div class="inline-form" style="margin-top:12px">
                    <form method="POST" action="{{ route('ordens.link', $ordem) }}">@csrf<button class="button" type="submit">Gerar novo link para o cliente</button></form>
                    <form method="POST" action="{{ route('ordens.link.revogar', $ordem) }}">@csrf<button class="button" type="submit">Revogar links do cliente</button></form>
                </div>
            @endif
        @endcan
    </div>
    @elseif (! $versoes->firstWhere('status', 'aprovado'))
        <div class="form-section"><h2>Serviços</h2><p class="muted">Aguardando aprovação do orçamento pelo cliente. Os serviços aparecem aqui quando forem aprovados.</p></div>
    @endif

    @php($aprovado = $versoes->firstWhere('status', 'aprovado'))
    @if ($aprovado && in_array($ordem->status, [\App\Enums\StatusOrdem::Autorizado, \App\Enums\StatusOrdem::EmExecucao, \App\Enums\StatusOrdem::ProntoParaEntrega], true))
        <div class="form-section" id="execucao">
            <h2>Execução</h2>
            <p class="hint">Autorizado: versão {{ $aprovado->versao }}. Somente estes serviços podem ser executados.</p>
            @can('diagnosticar', $ordem)
                @if ($ordem->status === \App\Enums\StatusOrdem::Autorizado)
                    <form method="POST" action="{{ route('execucao.iniciar', $ordem) }}">@csrf<button class="button primary" type="submit">Iniciar execução</button></form>
                @endif
            @endcan
            <ul class="checks" style="list-style:none;padding:0">
                @foreach ($aprovado->itens as $i)
                    <li>
                        @if ($i->concluido_em)
                            <span class="item-feito">✓</span> {{ \App\Support\Dinheiro::quantidadeExibicao($i->quantidade) }} × {{ $i->descricao }}@if ($i->extra) <span class="tag extra">extra</span>@endif <span class="muted">— concluído por {{ $i->concluidoPor?->name }} em {{ \App\Support\Fuso::exibir($i->concluido_em) }}</span>
                        @else
                            {{ \App\Support\Dinheiro::quantidadeExibicao($i->quantidade) }} × {{ $i->descricao }}@if ($i->extra) <span class="tag extra">extra</span>@endif
                            @if ($ordem->status === \App\Enums\StatusOrdem::EmExecucao)
                                @can('diagnosticar', $ordem)
                                    <form method="POST" action="{{ route('execucao.item', $ordem) }}" style="display:inline">@csrf<input type="hidden" name="item" value="{{ $i->id }}"><button class="button" type="submit">Marcar como concluído</button></form>
                                @endcan
                            @endif
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($ordem->status === \App\Enums\StatusOrdem::EmExecucao)
                @can('diagnosticar', $ordem)
                    <form method="POST" action="{{ route('execucao.pronto', $ordem) }}">
                        @csrf
                        @include('partials.campo', ['nome' => 'resumo', 'rotulo' => 'Resultado do serviço (opcional)'])
                        <button class="button primary" type="submit">Liberar para entrega</button>
                    </form>
                @endcan
            @endif
        </div>
    @endif
</section>
