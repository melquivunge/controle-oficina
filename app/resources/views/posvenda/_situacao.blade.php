{{-- Situação de pós-venda de uma OS a partir dos contatos (mais recente primeiro). --}}
@php($ultimo = $cs->first())
@php($nota = $cs->firstWhere(fn ($c) => $c->nota !== null)?->nota)
@if (! $ultimo)
    <span class="tag">Sem contato</span>
@else
    <span @class(['tag', 'pv-ok' => $cs->contains('resultado', 'atendeu'), 'off' => $ultimo->resultado === 'numero_errado'])>{{ $cs->contains('resultado', 'atendeu') ? 'Contatado' : $ultimo->rotuloResultado() }}</span>
    @if ($nota !== null)<span class="pv-nota {{ $nota >= 9 ? 'promotor' : ($nota >= 7 ? 'neutro' : 'detrator') }}" title="Nota do atendimento (0 a 10)">{{ $nota }}</span>@endif
    @if ($cs->contains(fn ($c) => $c->problema_servico || $c->negociacao_fora_padrao))<span class="tag extra">Relato</span>@endif
    <span class="subline">{{ $cs->count() }} contato(s)@if ($ultimo->retorno_em) · retorno {{ $ultimo->retorno_em->format('d/m') }}@endif</span>
@endif
