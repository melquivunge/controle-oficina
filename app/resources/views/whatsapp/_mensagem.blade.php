{{-- Bolha de mensagem: cliente à esquerda, equipe à direita. Status: ✓ enviada, ✓✓ entregue, ✓✓ verde lida. --}}
@php($midia = \App\Services\WhatsApp\Atendimento::urlMidia($m))
<li @class(['wa-msg', 'entrada' => $m->entrada(), 'saida' => ! $m->entrada(), 'celular' => $m->origem === 'celular', 'falhou' => $m->status === 'falhou'])>
    <div class="wa-bolha">
        @if (! $m->entrada())
            <span class="wa-autor">{{ $m->origem === 'celular' ? 'Enviada pelo celular' : ($m->autor?->name ?? 'Equipe') }}@if ($m->historico) · histórico importado @endif</span>
        @elseif ($m->historico)
            <span class="wa-autor">Histórico importado</span>
        @endif
        @switch($m->tipo)
            @case('imagem')
                @if ($midia)<a href="{{ $midia }}" target="_blank" rel="noopener"><img class="wa-imagem" src="{{ $midia }}" alt="Imagem {{ $m->entrada() ? 'enviada pelo cliente' : 'enviada pela equipe' }}" loading="lazy"></a>@endif
                @break
            @case('audio')
                @if ($midia)<audio controls preload="none" src="{{ $midia }}"><a href="{{ $midia }}">Baixar áudio</a></audio>@endif
                @break
            @case('video')
                @if ($midia)<video class="wa-imagem" controls preload="metadata" src="{{ $midia }}"><a href="{{ $midia }}">Baixar vídeo</a></video>@endif
                @break
            @case('documento')
                @if ($midia)<a class="wa-doc" href="{{ $midia }}">@include('partials.icone', ['i' => 'download']) {{ $m->midia_nome ?? 'Documento' }}</a>@endif
                @break
            @case('localizacao')
                <a class="wa-doc" href="https://www.openstreetmap.org/?mlat={{ $m->latitude }}&amp;mlon={{ $m->longitude }}#map=17/{{ $m->latitude }}/{{ $m->longitude }}" target="_blank" rel="noopener noreferrer">Localização: {{ number_format((float) $m->latitude, 5, ',', '') }}, {{ number_format((float) $m->longitude, 5, ',', '') }}</a>
                @break
            @case('modelo')
                <span class="wa-autor">Modelo aprovado: {{ $m->modelo }}</span>
                @break
        @endswitch
        @if (in_array($m->tipo, ['imagem', 'audio', 'video', 'documento'], true) && ! $midia)
            <p class="wa-midia-aviso">{{ $m->rotuloTipo() }} — {{ match ($m->midia_status) { 'pendente' => 'baixando…', 'rejeitada' => 'recusado: '.$m->ultimo_erro, 'falhou' => 'não foi possível baixar', default => 'indisponível' } }}</p>
        @endif
        @if ($m->texto)<p class="wa-texto">{{ $m->texto }}</p>@endif
        <span class="wa-meta">
            <time datetime="{{ $m->ocorrida_em->toIso8601String() }}">{{ \App\Support\Fuso::exibir($m->ocorrida_em, 'H:i') }}</time>
            @unless ($m->entrada())
                <span @class(['wa-status', 'lida' => $m->status === 'lida']) title="{{ $m->rotuloStatus() }}">
                    <span aria-hidden="true">{{ match ($m->status) { 'enviando' => '◷', 'enviada' => '✓', 'entregue', 'lida' => '✓✓', 'falhou' => '!', default => '' } }}</span><span class="sr-only">{{ $m->rotuloStatus() }}</span>
                </span>
            @endunless
        </span>
    </div>
    @if ($m->status === 'falhou')
        <div class="wa-falha" role="alert">
            <span>Não enviada: {{ $m->ultimo_erro ?? 'falha no provedor' }}</span>
            @if ($m->origem === 'sistema')
                <form method="POST" action="{{ route('whatsapp.tentar', $m) }}">@csrf<button class="button" type="submit">Tentar de novo</button></form>
            @endif
        </div>
    @endif
</li>
