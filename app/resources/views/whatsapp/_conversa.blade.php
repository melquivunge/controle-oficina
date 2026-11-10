{{-- Conversa aberta: histórico estilo chat + resposta + painel lateral (cliente, OS, ações). --}}
@php($janela = $conversa->janelaAberta())
<section class="wa-conversa" aria-labelledby="wa-titulo">
    <header class="wa-conversa-topo">
        <a class="wa-voltar" href="{{ route('whatsapp.index', $qs) }}">‹ <span>Conversas</span></a>
        <span class="ficha-avatar tom-{{ $conversa->id % 6 }}" aria-hidden="true">{{ $conversa->iniciais() }}</span>
        <div class="wa-conversa-id">
            <h2 id="wa-titulo">{{ $conversa->nomeExibicao() }}</h2>
            <small>{{ $conversa->telefonePara($usuario) }} · {{ $conversa->numero->nome }} · {{ $conversa->emTriagem() ? 'Triagem' : $conversa->unidade->nome }}</small>
        </div>
        <span class="tag wa-situacao-{{ $conversa->situacao }}">{{ $conversa->rotuloSituacao() }}</span>
    </header>

    <ol class="wa-mensagens" aria-label="Histórico da conversa">
        @php($diaAnterior = null)
        @forelse ($conversa->mensagens as $m)
            @php($dia = \App\Support\Fuso::exibir($m->ocorrida_em, 'd/m/Y'))
            @if ($dia !== $diaAnterior)
                <li class="wa-dia" aria-hidden="true"><span>{{ $dia }}</span></li>
                @php($diaAnterior = $dia)
            @endif
            @include('whatsapp._mensagem', ['m' => $m])
        @empty
            <li class="wa-dia"><span>Sem mensagens</span></li>
        @endforelse
        <li id="fim" class="wa-fim" tabindex="-1"><span class="sr-only">Fim do histórico</span></li>
    </ol>

    <div class="wa-compor">
        @if ($janela)
            <form method="POST" action="{{ route('whatsapp.responder', $conversa) }}" enctype="multipart/form-data" class="wa-compor-form">@csrf
                <div class="field wa-compor-texto">
                    <label for="wa-texto">Responder pelo WhatsApp</label>
                    <textarea id="wa-texto" name="texto" rows="2" maxlength="4096" placeholder="Escreva a mensagem" @error('texto') aria-invalid="true" aria-describedby="wa-texto-erro" @enderror>{{ old('texto') }}</textarea>
                    @error('texto')<span class="field-error" id="wa-texto-erro">{{ $message }}</span>@enderror
                </div>
                <div class="field wa-compor-anexo">
                    <label for="wa-anexo">Anexo <span>(opcional: imagem ou PDF)</span></label>
                    <input id="wa-anexo" name="anexo" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" @error('anexo') aria-invalid="true" aria-describedby="wa-anexo-erro" @enderror>
                    @error('anexo')<span class="field-error" id="wa-anexo-erro">{{ $message }}</span>@enderror
                </div>
                <button class="button primary" type="submit">Enviar resposta</button>
            </form>
            @if ($conversa->numero->provedor !== 'drope')<p class="hint">Janela de 24 h aberta até {{ \App\Support\Fuso::exibir($conversa->janelaFechaEm()) }}. Depois disso, só modelo aprovado.</p>@endif
        @else
            <p class="notice wa-janela" role="status">@include('partials.icone', ['i' => 'cadeado'])
                <span>Fora da janela de 24 h do WhatsApp: {{ $conversa->ultima_entrada_em ? 'a última mensagem do cliente foi em '.\App\Support\Fuso::exibir($conversa->ultima_entrada_em).'.' : 'o cliente ainda não escreveu.' }} Texto livre está bloqueado; envie um modelo aprovado.</span></p>
            @error('texto')<p class="field-error">{{ $message }}</p>@enderror
        @endif
        @if (! empty($conversa->numero->modelos))
            <details class="wa-modelo" @if (! $janela) open @endif>
                <summary>Enviar modelo aprovado</summary>
                <form method="POST" action="{{ route('whatsapp.modelo', $conversa) }}" class="inline-form">@csrf
                    @include('partials.campo', ['nome' => 'modelo', 'rotulo' => 'Modelo', 'id' => 'wa-modelo', 'tipo' => 'select',
                        'opcoes' => collect($conversa->numero->modelos)->mapWithKeys(fn ($mo) => [$mo['nome'] => $mo['nome'].($mo['descricao'] ? ' — '.$mo['descricao'] : '')])->all()])
                    <button class="button" type="submit">Enviar modelo</button>
                </form>
            </details>
        @elseif (! $janela)
            <p class="hint">Nenhum modelo aprovado cadastrado neste número. O proprietário cadastra em Configurações › Números de WhatsApp.</p>
        @endif
        @if ($podeSimular)
            <details class="wa-modelo">
                <summary>Simular resposta pelo celular da empresa</summary>
                <form method="POST" action="{{ route('whatsapp.simular-celular', $conversa) }}" class="inline-form">@csrf
                    <div class="field"><label for="wa-celular">Texto digitado no app do celular (simulação)</label><input id="wa-celular" name="texto_celular" maxlength="1000" required></div>
                    <button class="button" type="submit">Simular</button>
                </form>
            </details>
        @endif
    </div>
</section>

<aside class="wa-painel" aria-label="Detalhes e ações da conversa">
    <section class="cartao">
        <h3>Cliente</h3>
        @if ($conversa->cliente)
            <p class="wa-painel-destaque"><a href="{{ route('clientes.show', $conversa->cliente) }}">{{ $conversa->cliente->nome }}</a></p>
            <p class="hint">Vinculado pelo telefone ou manualmente. Contato no WhatsApp: {{ $conversa->contato_nome ?? '—' }}</p>
        @else
            <p class="hint">Nenhum cliente com este telefone. Se for cliente novo, cadastre abaixo; se já for conhecido, use "Vincular cliente".</p>
            @can('registrar_entrada')
                <details class="wa-novo-cliente" @if ($buscaCliente === '') open @endif>
                    <summary>Cadastrar novo cliente</summary>
                    <form method="POST" action="{{ route('whatsapp.cliente.novo', $conversa) }}">
                        @csrf
                        @include('partials.campo', ['nome' => 'nome', 'rotulo' => 'Nome do cliente', 'id' => 'wa-novo-nome', 'valorAtual' => $conversa->contato_nome, 'atributos' => ['autocomplete' => 'off', 'required' => 'required']])
                        <div class="field"><label for="wa-novo-tel">Telefone (da conversa)</label><input id="wa-novo-tel" type="text" value="{{ $conversa->telefonePara(auth()->user()) }}" disabled></div>
                        @include('partials.campo', ['nome' => 'documento', 'rotulo' => 'CPF ou CNPJ (opcional)', 'id' => 'wa-novo-doc', 'atributos' => ['inputmode' => 'numeric', 'autocomplete' => 'off']])
                        @include('partials.campo', ['nome' => 'email', 'rotulo' => 'E-mail (opcional)', 'id' => 'wa-novo-email', 'tipo' => 'email'])
                        <fieldset class="wa-novo-veiculo"><legend>Veículo (opcional)</legend>
                            @include('partials.placa-consulta', ['id' => 'wa-novo-placa'])
                            @include('partials.campo', ['nome' => 'marca', 'rotulo' => 'Marca', 'id' => 'wa-novo-marca'])
                            @include('partials.campo', ['nome' => 'modelo', 'rotulo' => 'Modelo', 'id' => 'wa-novo-modelo'])
                            @include('partials.campo', ['nome' => 'ano', 'rotulo' => 'Ano', 'id' => 'wa-novo-ano', 'atributos' => ['inputmode' => 'numeric', 'maxlength' => 4]])
                            @include('partials.campo', ['nome' => 'cor', 'rotulo' => 'Cor', 'id' => 'wa-novo-cor'])
                        </fieldset>
                        <div class="field"><label for="wa-novo-obs">Observações (opcional)</label>
                            <textarea id="wa-novo-obs" name="observacoes" rows="2" @error('observacoes') aria-invalid="true" aria-describedby="wa-novo-obs-erro" @enderror>{{ old('observacoes') }}</textarea>
                            @error('observacoes')<span class="field-error" id="wa-novo-obs-erro">{{ $message }}</span>@enderror</div>
                        <button class="button primary" type="submit">Cadastrar e vincular</button>
                    </form>
                </details>
            @endcan
        @endif
        @if ($conversa->cliente)
            @can('registrar_entrada')
                <a class="button wa-entrada" href="{{ route('entradas.nova', ['telefone' => $conversa->cliente->telefone, 'nome' => $conversa->cliente->nome]) }}">Registrar entrada de veículo</a>
            @endcan
        @endif
        <details @if ($buscaCliente !== '') open @endif>
            <summary>{{ $conversa->cliente ? 'Trocar cliente' : 'Vincular cliente' }}</summary>
            <form method="GET" action="{{ route('whatsapp.show', $conversa) }}" class="inline-form" role="search">
                @foreach ($qs as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                <div class="field"><label for="wa-busca-cliente">Buscar por nome, telefone ou placa</label><input id="wa-busca-cliente" name="busca_cliente" type="search" value="{{ $buscaCliente }}" minlength="2"></div>
                <button class="button" type="submit">Buscar</button>
            </form>
            @if ($buscaCliente !== '')
                <ul class="wa-resultados">
                    @forelse ($clientesEncontrados as $cl)
                        <li><form method="POST" action="{{ route('whatsapp.cliente', $conversa) }}">@csrf<input type="hidden" name="cliente_id" value="{{ $cl->id }}">
                            <button class="button" type="submit">Vincular {{ $cl->nome }} <small class="muted">final {{ substr($cl->telefone, -4) }}</small></button></form></li>
                    @empty
                        <li class="hint">Nenhum cliente encontrado.</li>
                    @endforelse
                </ul>
            @endif
            @if ($conversa->cliente)
                <form method="POST" action="{{ route('whatsapp.cliente', $conversa) }}">@csrf<button class="linklike" type="submit">Remover vínculo com o cliente</button></form>
            @endif
        </details>
    </section>

    @if ($conversa->cliente)
        <section class="cartao">
            <h3>OS abertas do cliente</h3>
            @forelse ($ordensAbertas as $o)
                <div @class(['wa-os', 'vinculada' => $conversa->ordem_id === $o->id])>
                    <a href="{{ route('ordens.show', $o) }}"><strong>{{ $o->numero }}</strong> <span class="chip-placa">{{ \Illuminate\Support\Str::limit($o->identificacao(), 30) }}</span></a>
                    <small class="muted">{{ $o->status->rotulo() }} · {{ $o->unidade->nome }}</small>
                    @if ($conversa->ordem_id === $o->id)
                        <span class="tag">Vinculada a esta conversa</span>
                    @else
                        <form method="POST" action="{{ route('whatsapp.ordem', $conversa) }}">@csrf<input type="hidden" name="ordem" value="{{ $o->numero }}"><button class="linklike" type="submit">Vincular esta OS</button></form>
                    @endif
                </div>
            @empty
                <p class="hint">Nenhuma OS aberta visível para você.</p>
            @endforelse
            @if ($conversa->ordem_id)
                @if (! $ordensAbertas->contains('id', $conversa->ordem_id) && $conversa->ordem && \App\Models\Ordem::visiveisPara($usuario)->whereKey($conversa->ordem_id)->exists())<p class="hint">Vinculada: {{ $conversa->ordem->numero }} ({{ $conversa->ordem->status->rotulo() }})</p>@endif
                <form method="POST" action="{{ route('whatsapp.ordem', $conversa) }}">@csrf<button class="linklike" type="submit">Remover vínculo com a OS</button></form>
            @endif
        </section>
    @endif

    <section class="cartao">
        <h3>Atendimento</h3>
        <form method="POST" action="{{ route('whatsapp.atribuir', $conversa) }}" class="wa-acao">@csrf
            @include('partials.campo', ['nome' => 'responsavel_id', 'rotulo' => 'Responsável', 'id' => 'wa-responsavel-conversa', 'tipo' => 'select', 'valorAtual' => $conversa->responsavel_id,
                'opcoes' => ['' => 'Sem responsável'] + $responsaveis->mapWithKeys(fn ($u) => [$u->id => $u->name.($u->unidade ? ' · '.$u->unidade->nome : '')])->all()])
            <button class="button" type="submit">Salvar responsável</button>
        </form>
        <form method="POST" action="{{ route('whatsapp.situacao', $conversa) }}" class="wa-acao">@csrf
            @include('partials.campo', ['nome' => 'situacao', 'rotulo' => 'Situação', 'id' => 'wa-situacao-conversa', 'tipo' => 'select', 'valorAtual' => $conversa->situacao === 'nova' ? 'em_atendimento' : $conversa->situacao,
                'opcoes' => ['em_atendimento' => 'Em atendimento', 'aguardando_cliente' => 'Aguardando cliente', 'resolvida' => 'Resolvida']])
            <div class="field"><label for="wa-motivo">Motivo <span>(obrigatório ao resolver)</span></label>
                <textarea id="wa-motivo" name="motivo" rows="2" maxlength="500" @error('motivo') aria-invalid="true" aria-describedby="wa-motivo-erro" @enderror>{{ old('motivo') }}</textarea>
                @error('motivo')<span class="field-error" id="wa-motivo-erro">{{ $message }}</span>@enderror</div>
            <button class="button" type="submit">Salvar situação</button>
        </form>
        @if ($conversa->situacao === 'resolvida')<p class="hint">Resolvida em {{ \App\Support\Fuso::exibir($conversa->resolvida_em) }}: {{ $conversa->motivo_resolucao }}</p>@endif
    </section>

    @if ($podeEncaminhar)
        <section class="cartao">
            <h3>{{ $conversa->emTriagem() ? 'Triagem' : 'Unidade' }}</h3>
            @if ($sugestao && $sugestao->id !== $conversa->unidade_id)<p class="hint">Sugestão: <strong>{{ $sugestao->nome }}</strong> (OS aberta mais recente do cliente).</p>@endif
            <form method="POST" action="{{ route('whatsapp.encaminhar', $conversa) }}" class="wa-acao">@csrf
                @include('partials.campo', ['nome' => 'unidade_id', 'rotulo' => 'Encaminhar para', 'id' => 'wa-encaminhar', 'tipo' => 'select', 'valorAtual' => $sugestao?->id,
                    'opcoes' => $unidadesEncaminhar->reject(fn ($u) => $u->id === $conversa->unidade_id)->mapWithKeys(fn ($u) => [$u->id => $u->nome])->all()])
                <div class="field"><label for="wa-observacao">Observação <span>(opcional)</span></label><input id="wa-observacao" name="observacao" maxlength="300"></div>
                <button class="button primary" type="submit">Encaminhar conversa</button>
            </form>
        </section>
    @endif
    <p class="hint wa-modo">{{ \App\Integracoes\WhatsApp\Provedores::simulacaoAtiva() ? 'Modo simulado: nada é enviado nem recebido de verdade.' : 'Número '.(['meta' => 'na API oficial (adaptador não homologado com conta real)', 'drope' => 'no Drope (QR Code, não oficial)'][$conversa->numero->provedor] ?? 'simulado').'.' }}
        @if ($conversa->numero->modo_conexao === 'coexistencia') Coexistência: respostas digitadas no celular da empresa também aparecem aqui.@endif</p>
</aside>
