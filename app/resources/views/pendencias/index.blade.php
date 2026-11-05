@extends('layouts.app', ['titulo' => 'Pendências e mensagens'])
@section('conteudo')
    <div class="page-head"><div><h1>Pendências e mensagens</h1><p class="subtitle">Alertas indicam situações a investigar; não representam acusação.</p></div></div>
    <nav class="abas" aria-label="Seções">
        <a href="{{ route('pendencias.index') }}" @if ($aba === 'pendencias') aria-current="page" @endif>Pendências</a>
        <a href="{{ route('pendencias.index', ['aba' => 'mensagens']) }}" @if ($aba === 'mensagens') aria-current="page" @endif>Mensagens (fila de envio)</a>
    </nav>

    @if ($aba === 'pendencias')
        <form method="GET" class="filtro-resolvidas">
            <label class="opcao-marcar"><input type="checkbox" name="resolvidas" value="1" @checked(request()->boolean('resolvidas')) onchange="this.form.submit()"><span>Mostrar resolvidas</span></label>
            <noscript><button class="button" type="submit">Aplicar</button></noscript>
            <p class="hint">{{ $abertas }} em aberto</p>
        </form>
        @forelse ($itens as $i)
            <article class="versao pendencia {{ $i['resolucao'] ? 'resolvida' : $i['gravidade'] }}">
                <header>
                    <div class="pendencia-titulo">
                        @if ($i['resolucao'])<span class="tag">Resolvida</span>@else<span class="tag {{ $i['gravidade'] === 'critico' ? 'off' : 'extra' }}">{{ $i['gravidade'] === 'critico' ? 'Crítico' : 'Atenção' }}</span>@endif
                        <strong>{{ $i['rotulo'] }}</strong>
                    </div>
                    <small class="muted">{{ $i['unidade'] }} · {{ \App\Support\Fuso::exibir($i['data']) }}@if ($i['ordem']) · {{ $i['ordem']->numero }}@endif</small>
                </header>
                <p class="pendencia-descricao">{{ $i['descricao'] }}</p>
                @if ($i['resolucao'])
                    <p class="hint">Resolução: {{ $i['resolucao']['texto'] }} — {{ $i['resolucao']['por'] }}, {{ \App\Support\Fuso::exibir($i['resolucao']['em']) }}</p>
                @endif
                <div class="pendencia-acoes">
                    @if ($i['ordem'])
                        <a class="button" href="{{ route('ordens.show', [$i['ordem'], 'aba' => in_array($i['tipo'], ['pagamento_revisao', 'entrega_excecao', 'cobranca_falha', 'devolucao_falhou', 'pagamento_vencido'], true) ? 'pagamentos' : ($i['tipo'] === 'divergencia' ? 'resumo' : 'documentos')]) }}">Abrir OS</a>
                    @elseif ($i['tipo'] === 'divergencia')
                        <a class="button" href="{{ route('conferencia.index', ['unidade' => $i['unidade_id']]) }}">Abrir conferência</a>
                    @endif
                    @if ($i['tipo'] === 'mensagem_falhou' && ! $i['resolucao'])
                        <a class="button" href="{{ route('pendencias.index', ['aba' => 'mensagens']) }}">Ver na fila</a>
                    @endif
                </div>
                @if ($i['resolvivel'] && ! $i['resolucao'])
                    <details class="pendencia-resolver" @if (old('_pendencia') === $i['tipo'].':'.$i['id']) open @endif>
                        <summary>Registrar resolução</summary>
                        <form method="POST" action="{{ route('pendencias.resolver', [$i['tipo'], $i['id']]) }}" class="inline-form">@csrf
                            <input type="hidden" name="_pendencia" value="{{ $i['tipo'] }}:{{ $i['id'] }}">
                            @include('partials.campo', ['nome' => 'resolucao', 'rotulo' => 'O que foi verificado e decidido', 'id' => 'resolucao-'.$i['tipo'].'-'.$i['id'], 'obrigatorio' => true, 'atributos' => ['minlength' => 5, 'maxlength' => 500], 'valorAtual' => old('_pendencia') === $i['tipo'].':'.$i['id'] ? old('resolucao') : ''])
                            <button class="button primary" type="submit">Salvar resolução</button>
                        </form>
                    </details>
                @endif
            </article>
        @empty
            <p class="muted">Nenhuma pendência em aberto nas suas unidades.</p>
        @endforelse
        {{ $itens->links() }}
    @else
        <section class="versao fila-aviso">
            @if ($simulado)
                <p><strong>Modo simulado:</strong> mensagens são SIMULADAS e nunca enviadas a ninguém.</p>
            @else
                <p>Nenhum provedor de mensagens homologado: tentativas de envio falham e ficam registradas aqui.</p>
            @endif
            <form method="POST" action="{{ route('pendencias.mensagens.processar') }}">@csrf
                <button class="button primary" type="submit">Processar fila agora</button>
            </form>
            <p class="hint">Reenvia para a fila as mensagens pendentes paradas há mais de 5 minutos nas suas unidades.</p>
        </section>
        @if ($mensagens->isEmpty())
            <p class="muted">Nenhuma mensagem registrada.</p>
        @else
            <div class="table-wrap"><table class="data-table lista-mensagens">
                <thead><tr><th>Mensagem</th><th>Canal e destino</th><th>Tentativas</th><th>Quando</th><th>Situação</th><th><span class="sr-only">Ações</span></th></tr></thead>
                <tbody>
                @foreach ($mensagens as $m)
                    <tr>
                        <td data-label="Mensagem"><strong>{{ $m->rotuloTipo() }}</strong> · @if ($numeros[$m->ordem_id] ?? null)<a href="{{ route('ordens.show', [$numeros[$m->ordem_id], 'aba' => 'documentos']) }}">{{ $numeros[$m->ordem_id] }}</a>@endif</td>
                        <td data-label="Canal e destino">{{ $m->canal }} · (**) *****-{{ substr($m->destino, -4) }}</td>
                        <td data-label="Tentativas">{{ $m->tentativas }}/{{ $maxTentativas }}</td>
                        <td data-label="Quando">{{ \App\Support\Fuso::exibir($m->updated_at) }}</td>
                        <td data-label="Situação"><span class="tag {{ ['falhou' => 'off', 'pendente' => 'extra'][$m->status] ?? '' }}">{{ $m->rotuloStatus() }}</span>
                            @if ($m->status === 'falhou' && $m->ultimo_erro)<span class="subline erro-texto">{{ $m->ultimo_erro }}</span>@endif</td>
                        <td data-label="Ações">
                            @if ($m->status === 'falhou')
                                <form method="POST" action="{{ route('pendencias.mensagens.reenviar', $m) }}">@csrf<button class="button" type="submit">Tentar de novo</button></form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            {{ $mensagens->links() }}
        @endif
    @endif
@endsection
