{{-- Conteúdo da seção fiscal. Também devolvido por fiscal.status (JSON) para acompanhar a emissão sem recarregar. --}}
@php($errosCancelar = $errors->getBag('fiscal_cancelar'))
@php($rotulos = ['pendente' => 'Processando…', 'processando' => 'Processando…', 'autorizado' => 'Autorizado', 'rejeitado' => 'Rejeitado', 'cancelado' => 'Cancelado'])
@error('fiscal')<p class="alert erro" role="alert">{{ $message }}</p>@enderror
@if ($errosCancelar->any())<p class="alert erro" role="alert">Documento não cancelado. Corrija a justificativa no formulário abaixo e confirme de novo.</p>@endif
@forelse ($docs as $d)
    @php($emAndamento = in_array($d->status, ['pendente', 'processando'], true))
    <article class="fiscal-doc fiscal-doc--{{ $d->status }}" id="fiscal-{{ $d->id }}">
        <header class="fiscal-doc__topo">
            <strong>{{ \App\Support\DadosFiscais::NOTAS[$d->tipo] ?? 'Nota' }} · {{ $d->numero ? 'Nº '.$d->numero : 'Documento #'.$d->id }} · {{ \App\Support\Dinheiro::formatar($d->valor_centavos) }}</strong>
            <span class="fiscal-estado fiscal-estado--{{ $d->status }}">@if ($emAndamento)<span class="fiscal-giro" aria-hidden="true"></span>@endif{{ $rotulos[$d->status] }}</span>
        </header>
        @if ($d->observacao)<p class="fiscal-doc__texto">Observação na nota: {{ $d->observacao }}</p>@endif
        @if ($emAndamento && ! $d->mensagem)
            <p class="fiscal-doc__texto">Enviando ao emissor. O resultado aparece aqui automaticamente.</p>
        @elseif ($d->status === 'pendente' && $d->mensagem)
            <p class="fiscal-doc__texto fiscal-doc__texto--erro">Não foi possível emitir agora: {{ $d->mensagem }}</p>
        @elseif ($d->status === 'rejeitado')
            <p class="fiscal-doc__texto fiscal-doc__texto--erro">Motivo da rejeição: {{ $d->mensagem ?: 'não informado pelo emissor' }}</p>
        @elseif ($d->status === 'autorizado')
            <p class="fiscal-doc__texto">Autorizado em {{ \App\Support\Fuso::exibir($d->autorizado_em) }}{{ $d->protocolo && $d->protocolo !== $d->chave_acesso ? ' · protocolo '.$d->protocolo : '' }}.@if ($d->ambiente === 2) <strong>Homologação: sem validade fiscal.</strong>@endif</p>
            @if ($d->chave_acesso)<p class="hint">Chave de acesso: <span class="mono">{{ $d->chave_acesso }}</span></p>@endif
        @elseif ($d->status === 'cancelado')
            <p class="fiscal-doc__texto">Cancelado em {{ \App\Support\Fuso::exibir($d->cancelado_em) }}. Justificativa: {{ $d->cancelamento_motivo }}</p>
        @endif
        <p class="hint">Tentativas: {{ $d->tentativas }}</p>
        <div class="fiscal-doc__acoes">
            @if ($d->arquivo)<a class="button" href="{{ route('fiscal.baixar', [$ordem, $d]) }}" target="_blank" rel="noopener">{{ $d->status === 'cancelado' ? 'Abrir documento cancelado' : 'Abrir documento' }}</a>@endif
            @if (! $d->arquivo && $d->xml && $d->tipo === 'nfse' && $d->status === 'autorizado')<a class="button" href="{{ route('fiscal.baixar', [$ordem, $d, 'pdf' => 1]) }}">Baixar PDF</a>@endif
            @if ($d->xml)<a class="button" href="{{ route('fiscal.baixar', [$ordem, $d, 'xml' => 1]) }}">Baixar XML</a>@endif
            @can('cobrar')
                @if ($d->status === 'rejeitado' || ($d->status === 'pendente' && $d->mensagem))
                    <form method="POST" action="{{ route('fiscal.reprocessar', [$ordem, $d]) }}">@csrf<button class="button" type="submit">Tentar de novo</button></form>
                @endif
                @if ($d->status === 'pendente')
                    <form method="POST" action="{{ route('fiscal.interromper', [$ordem, $d]) }}">@csrf<button class="button" type="submit" data-confirmar="Parar de tentar emitir esta nota?">Parar emissão</button></form>
                @endif
            @endcan
        </div>
        @if ($d->status === 'autorizado')
            @can('cancelar_ordem')
                @php($aberto = (int) session('fiscal_cancelar') === $d->id)
                <details @if ($aberto) open @endif class="fiscal-cancelar" data-fiscal-cancelar="{{ $d->id }}">
                    <summary>Cancelar este documento</summary>
                    <form method="POST" action="{{ route('fiscal.cancelar', [$ordem, $d]) }}">@csrf
                        <div class="field">
                            <label for="fiscal-motivo-{{ $d->id }}">Justificativa do cancelamento (mínimo 15 caracteres)</label>
                            <input id="fiscal-motivo-{{ $d->id }}" name="motivo" type="text" required minlength="15" maxlength="255" value="{{ $aberto ? old('motivo_fiscal') : '' }}"
                                   @if ($aberto && $errosCancelar->has('motivo')) aria-invalid="true" aria-describedby="fiscal-motivo-{{ $d->id }}-erro" @endif @if ($aberto) autofocus @endif>
                            @if ($aberto && $errosCancelar->has('motivo'))<span class="field-error" id="fiscal-motivo-{{ $d->id }}-erro">{{ $errosCancelar->first('motivo') }}</span>@endif
                        </div>
                        <button class="button fiscal-perigo" type="submit">Confirmar cancelamento</button>
                    </form>
                </details>
            @endcan
        @endif
    </article>
@empty
    <p class="muted">Nenhum documento emitido.</p>
@endforelse
@can('cobrar')
    @if ($ordem->orcamentoAprovado && $ordem->status !== \App\Enums\StatusOrdem::Cancelado)
        {{-- 09/11/2026: serviços → NFS-e; peças/produtos → NF-e. Mostra o que falta emitir. --}}
        @php($partes = array_filter(\App\Support\DadosFiscais::partes($ordem->orcamentoAprovado->itens, (int) $ordem->orcamentoAprovado->desconto_centavos), fn ($v) => $v > 0))
        @php($faltam = array_diff_key($partes, array_flip($docs->whereIn('status', ['pendente', 'processando', 'autorizado'])->pluck('tipo')->all())))
        @if ($faltam)
            <p class="hint">A emitir: @foreach ($faltam as $t => $v){{ \App\Support\DadosFiscais::NOTAS[$t] }} de {{ \App\Support\Dinheiro::formatar($v) }}@if (! $loop->last) e @endif @endforeach.</p>
            <form method="POST" action="{{ route('fiscal.solicitar', $ordem) }}">@csrf
                @include('partials.campo', ['nome' => 'observacao', 'rotulo' => 'Observação na nota (opcional)', 'id' => 'fiscal-observacao',
                    'atributos' => ['maxlength' => 500, 'autocomplete' => 'off'], 'ajuda' => 'Sai nas informações complementares da NF-e e no fim da descrição da NFS-e. Até 500 caracteres.'])
                <button class="button primary" type="submit">{{ count($faltam) > 1 ? 'Emitir as 2 notas fiscais' : 'Emitir '.\App\Support\DadosFiscais::NOTAS[array_key_first($faltam)] }}</button></form>
        @endif
    @endif
@endcan
