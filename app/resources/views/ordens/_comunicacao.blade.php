@php($docs = \App\Models\FiscalDocument::where('ordem_id', $ordem->id)->orderByDesc('id')->get())
@php($mensagens = \App\Models\MessageDelivery::where('ordem_id', $ordem->id)->orderByDesc('id')->get())
<section class="form-panel" style="margin-bottom:20px" id="fiscal">
    <div class="form-section">
        <h2>Documento fiscal</h2>
        @if (config('oficina.integracoes') === 'simulado')<p class="hint">Simulação: documentos gerados aqui são <strong>SEM VALIDADE FISCAL</strong>. A emissão real depende da validação do contador e do provedor.</p>@endif
        @php($acompanhar = $docs->whereIn('status', ['pendente', 'processando'])->isNotEmpty())
        <div data-fiscal data-url="{{ route('fiscal.status', $ordem) }}" data-acompanhar="{{ $acompanhar ? '1' : '0' }}">
            @include('ordens._fiscal', ['docs' => $docs])
        </div>
        <p class="sr-only" role="status" aria-live="polite" data-fiscal-aviso></p>
        @if ($acompanhar)<p class="hint" data-fiscal-atualizar><a href="{{ route('ordens.show', $ordem) }}?aba=documentos#fiscal">Atualizar</a> para ver o resultado da emissão.</p>@endif
    </div>
    <div class="form-section" id="mensagens">
        <h2>Mensagens ao cliente</h2>
        @forelse ($mensagens as $m)
            <p><strong>{{ $m->rotuloTipo() }}</strong> · <span @class(['tag', 'off' => $m->status === 'falhou'])>{{ $m->rotuloStatus() }}</span>
                <span class="subline">{{ \App\Support\Fuso::exibir($m->created_at) }} · {{ $m->canal === 'simulado' ? 'canal simulado (ninguém foi contatado)' : $m->canal }} · tentativas {{ $m->tentativas }}{{ $m->ultimo_erro ? ' · '.$m->ultimo_erro : '' }}</span>
                @if ($m->status === 'falhou')
                    @can('registrar_entrada')<form method="POST" action="{{ route('mensagens.reenviar', [$ordem, $m]) }}" style="display:inline">@csrf<button class="button" type="submit">Reenviar</button></form>@endcan
                @endif
            </p>
        @empty
            <p class="muted">Nenhuma mensagem.</p>
        @endforelse
    </div>
</section>
