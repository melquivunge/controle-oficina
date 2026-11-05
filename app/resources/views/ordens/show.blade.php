@extends('layouts.app', ['titulo' => $ordem->numero])
@section('conteudo')
    @php($u = auth()->user())
    <div class="page-head">
        <div>
            <h1>{{ $ordem->numero }} · {{ $ordem->cliente->nome }}</h1>
            <p class="subtitle">{{ $ordem->unidade->nome }} · Entrada {{ \App\Support\Fuso::exibir($ordem->entrada_em) }} por {{ $ordem->autor->name }}</p>
        </div>
        @include('partials.status', ['status' => $ordem->status])
    </div>
    @error('status')<p class="alert erro" role="alert">{{ $message }}</p>@enderror
    @error('item')<p class="alert erro" role="alert">{{ $message }}</p>@enderror
    @error('decisao')<p class="alert erro" role="alert">{{ $message }}</p>@enderror
    @if ($ordem->contingencia)
        <p class="notice">Atendimento de contingência: horário original {{ \App\Support\Fuso::exibir($ordem->entrada_em) }}, regularizado em {{ \App\Support\Fuso::exibir($ordem->created_at) }} por {{ $ordem->autor->name }}. {{ $ordem->contingencia_nota }}</p>
    @endif
    @if ($ordem->status === \App\Enums\StatusOrdem::Cancelado)
        <p class="alert erro">Ordem cancelada. Motivo: {{ $ordem->cancelamento_motivo }}</p>
    @endif

    {{-- Bloco A: valores, pagamentos e contato só com permissão (mecânico nunca); dados financeiros nem são calculados sem ela. --}}
    @php($verValores = $u->can('ver_valores'))
    @php($verContato = $u->can('ver_contato_cliente'))
    @php($fin = $verValores ? app(\App\Services\Financeiro::class)->resumo($ordem) : null)
    @php($atrasada = $ordem->entregaAtrasada())
    @php($aprovadoAtual = $ordem->orcamentoAprovado()->with('itens')->first())
    @php($abas = ['resumo' => 'Resumo', 'diagnostico' => 'Diagnóstico e fotos', 'orcamento' => $verValores ? 'Orçamento e execução' : 'Serviços e execução'] + ($verValores ? ['pagamentos' => 'Pagamentos e entrega', 'documentos' => 'Documentos e mensagens'] : []) + ['historico' => 'Histórico'])
    @php($aba = array_key_exists(request('aba'), $abas) ? request('aba') : 'resumo')
    {{-- ponytail: abas por link (?aba=); painéis inativos ficam no HTML com hidden, sem JS --}}
    <nav class="abas" aria-label="Seções da ordem">
        @foreach ($abas as $chave => $rotulo)
            <a href="{{ request()->fullUrlWithQuery(['aba' => $chave]) }}" @if ($aba === $chave) aria-current="page" @endif>{{ $rotulo }}</a>
        @endforeach
    </nav>

    <div class="aba-painel" @if ($aba !== 'resumo') hidden @endif>
        <section class="summary quatro" aria-label="Resumo da ordem">
            <article><p>Entrada</p><strong>{{ \App\Support\Fuso::exibir($ordem->entrada_em, 'd/m H:i') }}</strong><small>por {{ $ordem->autor->name }}</small></article>
            <article><p>Entrega prevista</p><strong @class(['prazo-atrasado' => $atrasada])>{{ $ordem->entrega_prevista_em?->format('d/m') ?? '—' }}</strong><small>@if ($atrasada)Atrasada · @endif{{ $ordem->entrega_prevista_em ? \Illuminate\Support\Str::ucfirst($ordem->entrega_prevista_em->locale('pt_BR')->isoFormat('dddd')).' · dias úteis' : 'Ainda não definida' }}</small></article>
            @if ($verValores)
            <article><p>Aprovado</p><strong>{{ \App\Support\Dinheiro::formatar($fin['aprovado']) }}</strong><small>Versão aprovada pelo cliente</small></article>
            <article><p>Saldo a receber</p><strong @class(['accent' => $fin['saldo'] === 0 && $fin['aprovado'] > 0])>{{ \App\Support\Dinheiro::formatar($fin['saldo']) }}</strong><small>@if ($fin['em_revisao'])Em revisão: {{ \App\Support\Dinheiro::formatar($fin['em_revisao']) }}@else Recebido {{ \App\Support\Dinheiro::formatar($fin['recebido'] - $fin['devolvido']) }}@endif</small></article>
            @else
            @php($totalItens = $aprovadoAtual?->itens->count() ?? 0)
            <article><p>Serviços concluídos</p><strong>{{ $aprovadoAtual ? $aprovadoAtual->itens->whereNotNull('concluido_em')->count().'/'.$totalItens : '—' }}</strong><small>{{ $aprovadoAtual ? 'da versão aprovada' : 'Aguardando aprovação do cliente' }}</small></article>
            <article><p>Fotos</p><strong>{{ $ordem->anexos->count() }}</strong><small>na aba Diagnóstico e fotos</small></article>
            @endif
        </section>
        @if ($atrasada)<p class="alert erro" role="status">Entrega atrasada: a data prevista era {{ $ordem->entrega_prevista_em->format('d/m/Y') }} e a ordem ainda não está pronta.</p>@endif
        <div class="form-grid">
            <div>
                @php($proxima = match ($ordem->status) {
                    \App\Enums\StatusOrdem::Diagnostico => ['diagnostico', 'Registrar o diagnóstico'],
                    \App\Enums\StatusOrdem::AguardandoAprovacao => ['orcamento', 'Acompanhar a aprovação do orçamento'],
                    \App\Enums\StatusOrdem::Autorizado, \App\Enums\StatusOrdem::EmExecucao => ['orcamento', 'Executar os serviços aprovados'],
                    \App\Enums\StatusOrdem::ProntoParaEntrega => $verValores ? ['pagamentos', 'Receber e entregar o veículo'] : null,
                    \App\Enums\StatusOrdem::Entregue => $verValores && $fin['saldo'] > 0 ? ['pagamentos', 'Receber o saldo em aberto'] : null,
                    default => null,
                })
                @if ($proxima)
                    <section class="helper" style="margin-bottom:20px"><h2>Próximo passo</h2><p>{{ $proxima[1] }}.</p>
                        <a class="button primary" href="{{ request()->fullUrlWithQuery(['aba' => $proxima[0]]) }}">Abrir {{ \Illuminate\Support\Str::lower($abas[$proxima[0]]) }} →</a></section>
                @endif
                <section class="form-panel" style="margin-bottom:20px">
                <div class="form-section">
                    <h2>Veículo e cliente</h2>
                    <dl class="detalhes">
                        @if ($ordem->veiculo)
                        <dt>Placa</dt><dd><span class="plate">{{ $ordem->veiculo->placaFormatada() }}</span> {{ $ordem->veiculo->modelo }}</dd>
                        <dt>Cor</dt><dd>{{ $ordem->veiculo->cor ?: 'Não informada' }}</dd>
                        @else
                        <dt>Item</dt><dd><span class="item-avulso">Sem veículo</span> {{ $ordem->item_descricao }}</dd>
                        @endif
                        <dt>Cliente</dt><dd>@can('registrar_entrada')<a href="{{ route('clientes.show', $ordem->cliente) }}">{{ $ordem->cliente->nome }}</a>@else{{ $ordem->cliente->nome }}@endcan @if ($verContato)· {{ $ordem->cliente->telefoneFormatado() }}@endif</dd>
                        @if ($verContato && \App\Support\Endereco::preenchido($ordem->cliente))<dt>Endereço</dt><dd>{{ $ordem->cliente->enderecoTexto() }}</dd>@endif
                        @if ($verValores)<dt>Vendedor</dt><dd>{{ $ordem->vendedor?->name ?? 'Definido ao enviar o orçamento' }}</dd>@endif
                        <dt>Chegada</dt><dd>{{ \App\Support\Fuso::exibir($ordem->entrada_em) }}</dd>
                        <dt>Entrega prevista</dt><dd>@if ($ordem->entrega_prevista_em){{ $ordem->entrega_prevista_em->format('d/m/Y') }} @if ($atrasada)<span class="tag atrasada">atrasada</span>@endif<span class="muted"> · definida por {{ $ordem->prazoDefinidoPor?->name }}</span>@else<span class="muted">Ainda não definida pela gerência</span>@endif</dd>
                        <dt>Quilometragem</dt><dd>{{ $ordem->quilometragem !== null ? number_format($ordem->quilometragem, 0, ',', '.').' km' : 'Não informada' }}</dd>
                        <dt>Mecânico</dt><dd>{{ $ordem->mecanico?->name ?? 'Sem responsável' }}</dd>
                    </dl>
                </div>
                <div class="form-section">
                    <h2>Motivo da visita</h2>
                    <p style="white-space:pre-line">{{ $ordem->motivo }}</p>
                    @if ($ordem->observacoes)<p class="muted" style="white-space:pre-line">{{ $ordem->observacoes }}</p>@endif
                </div>
                <div class="form-section">
                    <h2>Serviço a executar</h2>
                    @if ($aprovadoAtual)
                        <ul class="servicos-aprovados">
                            @foreach ($aprovadoAtual->itens as $i)
                                <li>{{ \App\Support\Dinheiro::quantidadeExibicao($i->quantidade) }} × {{ $i->descricao }}@if ($i->extra) <span class="tag extra">extra</span>@endif @if ($i->concluido_em)<span class="item-feito">✓ concluído</span>@endif</li>
                            @endforeach
                        </ul>
                    @else
                        <p class="muted">Aguardando aprovação do orçamento pelo cliente.</p>
                    @endif
                    @if ($ordem->diagnostico)<h3 style="margin-top:16px">Diagnóstico</h3><p style="white-space:pre-line">{{ $ordem->diagnostico }}</p>@endif
                </div>
                </section>
            </div>
            <aside class="form-aside">
            @if ($verValores)
                <section class="helper">
                    <h2>Ordem impressa</h2>
                    <p>Versão A4 para o cliente assinar (não é documento fiscal).</p>
                    <a class="button" href="{{ route('ordens.impressao', $ordem) }}" target="_blank" rel="noopener">Imprimir OS</a>
                    <div class="os-whatsapp">
                        <form method="POST" action="{{ route('ordens.os.whatsapp', $ordem) }}">@csrf<input type="hidden" name="canal" value="sistema"><button class="button" type="submit">Enviar por WhatsApp</button></form>
                        <form method="POST" action="{{ route('ordens.os.whatsapp', $ordem) }}" target="_blank">@csrf<input type="hidden" name="canal" value="manual"><button class="os-wa-manual" type="submit">Abrir no meu WhatsApp</button></form>
                    </div>
                    <p class="hint">Envia o link da OS (somente leitura) ao telefone do cliente. O status aparece em "Documentos e mensagens".</p>
                    @error('telefone')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                </section>
            @endif
            @can('definirVendedor', $ordem)
                <details class="helper recolhivel" id="vendedor" @if ($errors->has('vendedor_id')) open @endif>
                    <summary><h2>Vendedor</h2></summary>
                    @php($opcoesVendedor = app(\App\Services\Vendedores::class)->opcoes($ordem))
                    <form method="POST" action="{{ route('ordens.vendedor', $ordem) }}">
                        @csrf
                        @include('partials.campo', ['nome' => 'vendedor_id', 'rotulo' => 'Quem fechou a venda', 'tipo' => 'select', 'id' => 'vendedor-os', 'opcoes' => ['' => 'Escolha'] + $opcoesVendedor->pluck('name', 'id')->all(), 'valorAtual' => $ordem->vendedor_id])
                        <button class="button" type="submit">{{ $ordem->vendedor_id ? 'Alterar vendedor' : 'Definir vendedor' }}</button>
                    </form>
                </details>
            @endcan
            @if ($ordem->status === \App\Enums\StatusOrdem::Entrada)
                @can('diagnosticar', $ordem)
                    <section class="helper">
                        <h2>Próximo passo</h2>
                        <p>Comece o diagnóstico para registrar o que o veículo precisa.</p>
                        <form method="POST" action="{{ route('ordens.diagnostico.iniciar', $ordem) }}">@csrf<button class="button primary" type="submit">Iniciar diagnóstico</button></form>
                    </section>
                @endcan
            @endif
            @can('definirPrazo', $ordem)
                <details class="helper recolhivel" id="prazo" @if ($errors->hasAny(['prazo', 'prazo_dias', 'prazo_data'])) open @endif>
                    <summary><h2>Data de entrega</h2></summary>
                    <p>Conte em dias úteis (seg–sex, sem feriados) a partir de hoje, ou escolha a data.</p>
                    @error('prazo')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    <form method="POST" action="{{ route('ordens.prazo', $ordem) }}" class="form-prazo">
                        @csrf
                        @include('partials.campo', ['nome' => 'prazo_dias', 'rotulo' => 'Prazo em dias úteis', 'tipo' => 'number', 'id' => 'prazo-dias-os', 'atributos' => ['min' => 1, 'max' => config('oficina.prazo_entrega_max_dias_uteis', 120), 'inputmode' => 'numeric']])
                        @include('partials.campo', ['nome' => 'prazo_data', 'rotulo' => 'ou data', 'tipo' => 'date', 'id' => 'prazo-data-os'])
                        <button class="button" type="submit">{{ $ordem->entrega_prevista_em ? 'Alterar data' : 'Definir data' }}</button>
                    </form>
                </details>
            @endcan
            @if (! $ordem->status->encerrada())
                @can('atribuir', $ordem)
                    <details class="helper recolhivel" @if ($errors->has('mecanico_id')) open @endif>
                        <summary><h2>Mecânico responsável</h2></summary>
                        @if ($mecanicos->isEmpty())
                            <p class="muted">Nenhum mecânico ativo nesta unidade.</p>
                        @else
                            <form method="POST" action="{{ route('ordens.atribuir', $ordem) }}">
                                @csrf
                                @include('partials.campo', ['nome' => 'mecanico_id', 'rotulo' => 'Mecânico', 'tipo' => 'select', 'opcoes' => ['' => 'Escolha'] + $mecanicos->pluck('name', 'id')->all(), 'valorAtual' => $ordem->mecanico_id])
                                <button class="button" type="submit">Atribuir</button>
                            </form>
                        @endif
                    </details>
                @endcan
                @can('cancelar', $ordem)
                    <section class="helper">
                        <h2>Cancelar ordem</h2>
                        <p>O cancelamento fica no histórico e não estorna valores automaticamente.</p>
                        <form method="POST" action="{{ route('ordens.cancelar', $ordem) }}">
                            @csrf
                            @include('partials.campo', ['nome' => 'motivo', 'rotulo' => 'Motivo do cancelamento', 'id' => 'motivo-cancelamento'])
                            <button class="button perigo" type="submit">Cancelar ordem</button>
                        </form>
                    </section>
                @endcan
            @endif
            </aside>
        </div>
    </div>

    <div class="aba-painel" @if ($aba !== 'diagnostico') hidden @endif>
        <section class="form-panel">
                <div class="form-section">
                    <h2>Diagnóstico</h2>
                    @can('diagnosticar', $ordem)
                        @if (in_array($ordem->status, [\App\Enums\StatusOrdem::Diagnostico, \App\Enums\StatusOrdem::AguardandoAprovacao, \App\Enums\StatusOrdem::EmExecucao], true))
                            <form method="POST" action="{{ route('ordens.diagnostico', $ordem) }}">
                                @csrf
                                <div class="field">
                                    <label for="diagnostico">O que foi encontrado</label>
                                    <textarea id="diagnostico" name="diagnostico" rows="4" @error('diagnostico') aria-invalid="true" aria-describedby="diagnostico-erro" @enderror>{{ old('diagnostico', $ordem->diagnostico) }}</textarea>
                                    @error('diagnostico')<span class="field-error" id="diagnostico-erro">{{ $message }}</span>@enderror
                                </div>
                                <button class="button" type="submit">Salvar diagnóstico</button>
                            </form>
                        @else
                            <p class="muted">{{ $ordem->diagnostico ?: 'Ainda não registrado.' }}</p>
                        @endif
                    @else
                        <p class="muted" style="white-space:pre-line">{{ $ordem->diagnostico ?: 'Ainda não registrado.' }}</p>
                    @endcan
                </div>
                <div class="form-section">
                    <h2>Fotos</h2>
                    @forelse ($ordem->anexos as $a)
                        <p><a href="{{ route('anexos.show', $a) }}" target="_blank" rel="noopener">{{ $a->nome_original }}</a> <span class="muted">· {{ \App\Support\Fuso::exibir($a->created_at) }}</span></p>
                    @empty
                        <p class="muted">Nenhuma foto.</p>
                    @endforelse
                    @can('anexar', $ordem)
                        <form method="POST" action="{{ route('ordens.anexar', $ordem) }}" enctype="multipart/form-data" class="inline-form">
                            @csrf
                            <div class="field"><label for="foto">Adicionar foto</label><input id="foto" type="file" name="foto" accept="image/jpeg,image/png,image/webp" @error('foto') aria-invalid="true" aria-describedby="foto-erro" @enderror>
                                @error('foto')<span class="field-error" id="foto-erro">{{ $message }}</span>@enderror</div>
                            <button class="button" type="submit">Enviar foto</button>
                        </form>
                    @endcan
                </div>
        </section>
    </div>

    <div class="aba-painel" @if ($aba !== 'orcamento') hidden @endif>
        @include('ordens._orcamento')
    </div>

    @isset($abas['pagamentos'])
        <div class="aba-painel" @if ($aba !== 'pagamentos') hidden @endif>
            @include('ordens._financeiro')
            @if ($fin['aprovado'] === 0 && ! $ordem->pagamentos()->exists())<p class="muted">Sem orçamento aprovado: ainda não há valores a receber.</p>@endif
        </div>
    @endisset

    @isset($abas['documentos'])
        <div class="aba-painel" @if ($aba !== 'documentos') hidden @endif>
            @include('ordens._comunicacao')
        </div>
    @endisset

    <div class="aba-painel" @if ($aba !== 'historico') hidden @endif>
        <section>
                <ol class="timeline">
                    @foreach ($historico as $h)
                        <li><strong>{{ \App\Support\Acoes::rotulo($h->acao) }}</strong>
                            @if ($h->acao === 'ordem_status') — {{ \App\Enums\StatusOrdem::tryFrom($h->antes['status'] ?? '')?->rotulo() }} → {{ \App\Enums\StatusOrdem::tryFrom($h->depois['status'] ?? '')?->rotulo() }}@endif
                            <span class="subline">{{ \App\Support\Fuso::exibir($h->ocorrido_em) }} · {{ $h->ator?->name ?? 'Sistema' }}{{ $h->motivo ? ' · '.$h->motivo : '' }}</span></li>
                    @endforeach
                </ol>
        </section>
    </div>
@endsection
