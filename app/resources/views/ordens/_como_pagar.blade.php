{{-- "Como o cliente vai pagar?" (decisão 7 de 30/10/2026). Requer $ordem, $u, $fin, $combinadoSit.
     Seletor por link (?modo=) funciona sem JS; resources/js/app.js troca o painel sem recarregar e monta o exemplo.
     Permissões reais no servidor (Financeiro/Combinados/PagamentoDividido); aqui só se escondem opções indisponíveis. --}}
@php($D = \App\Support\Dinheiro::class)
@php($podeAgora = $u->pode('cobrar') || $u->pode('receber_manual'))
@php($podeCombinar = $u->pode('combinar_pagamento') && $u->papel !== \App\Enums\Papel::Mecanico)
@php($formas = array_filter(['pix' => $u->pode('cobrar'), 'pix_manual' => $u->pode('receber_manual'), 'dinheiro' => $u->pode('receber_manual'), 'cartao_externo' => $u->pode('receber_manual')]))
@php($rotulosForma = ['pix' => 'Pix (cobrança do sistema)', 'pix_manual' => 'Pix já recebido no app do banco', 'dinheiro' => 'Dinheiro', 'cartao_externo' => 'Cartão na maquininha'])
@php($ajudaForma = ['pix' => 'Gera um Pix na conta oficial. Só conta como recebido quando o banco confirmar.', 'dinheiro' => 'Registra o valor recebido em mãos.', 'cartao_externo' => 'Registra o valor passado na maquininha. Anexe a foto do recibo, se tiver.', 'pix_manual' => 'O cliente já fez o Pix. Confira no app do banco que o valor entrou e marque a conferência.'])
@php($modos = ['agora' => 'Tudo agora', 'parte' => 'Parte agora, parte depois', 'depois' => 'Tudo depois'])
@php($modo = old('modo', request('modo', 'agora')))
@php($modo = array_key_exists($modo, $modos) && ($modo === 'agora' || $podeCombinar) ? $modo : 'agora')
@php($hoje = \App\Support\Fuso::exibir(now(), 'Y-m-d'))
@php($proxima = $combinadoSit ? $combinadoSit['proxima'] : null)
@php($formaPadrao = old('forma_agora', array_key_first($formas)))
@if ($podeAgora || $podeCombinar)
<div class="form-section pg-como" id="como-pagar" data-pg data-saldo="{{ $fin['saldo'] }}">
    <h2>{{ $combinadoSit ? 'Receber pagamento' : 'Como o cliente vai pagar?' }}</h2>

    @if ($combinadoSit)
        <p class="hint">Já existe pagamento combinado para esta ordem.@if ($proxima) Próxima parcela: <strong>{{ $D::formatar($proxima->emAbertoCentavos()) }}</strong> com vencimento em {{ $proxima->vencimento->format('d/m/Y') }}{{ $proxima->rotuloMeio() ? ' ('.$proxima->rotuloMeio().')' : '' }}.@endif</p>
    @else
        <nav class="segmento pg-segmento" aria-label="Como o cliente vai pagar">
            @foreach ($modos as $chave => $rotulo)
                @if ($chave === 'agora' || $podeCombinar)
                    <a href="{{ request()->fullUrlWithQuery(['aba' => 'pagamentos', 'modo' => $chave]) }}#como-pagar" data-pg-modo="{{ $chave }}" @if ($modo === $chave) aria-current="true" @endif>{{ $rotulo }}</a>
                @else
                    <span class="pg-indisponivel" aria-disabled="true" title="Para combinar o restante com data, peça ao gerente">{{ $rotulo }}</span>
                @endif
            @endforeach
        </nav>
        @unless ($podeCombinar)
            <p class="notice pg-aviso">Você pode receber tudo ou só uma parte agora. <strong>Para combinar o restante com data, peça ao gerente.</strong></p>
        @endunless
    @endif

    {{-- Tudo agora (ou próxima parcela do combinado): valor livre até o saldo. --}}
    @if ($podeAgora)
    <form method="POST" action="{{ route('pagamentos.agora', $ordem) }}" enctype="multipart/form-data" class="pg-painel" data-pg-painel="agora" @if ($modo !== 'agora' && ! $combinadoSit) hidden @endif>
        @csrf
        <input type="hidden" name="modo" value="agora">
        @include('ordens._pg_formas', ['sufixo' => 'agora', 'legenda' => 'Forma de pagamento'])
        <div class="fields">
            @include('partials.campo', ['nome' => 'valor_agora', 'rotulo' => 'Valor recebido agora (R$)', 'id' => 'pg-valor-agora',
                'valorAtual' => $D::paraCampo($proxima ? min($fin['saldo'], $proxima->emAbertoCentavos()) : $fin['saldo']), 'atributos' => ['inputmode' => 'decimal', 'autocomplete' => 'off'],
                'ajuda' => 'Saldo a receber: '.$D::formatar($fin['saldo']).'. Pode ser um valor menor.'])
            @include('partials.campo', ['nome' => 'observacao', 'rotulo' => 'Observação (opcional, ex.: NSU do cartão)', 'id' => 'pg-obs-agora'])
        </div>
        @if ($u->pode('receber_manual'))
            {{-- 09/11/2026: comprovante do Pix ou recibo da maquininha (opcional). Conferido no fechamento do caixa. --}}
            <fieldset class="pg-comprovante">
                <legend>Comprovante (opcional)</legend>
                <div class="field">
                    <label for="pg-comprovante">Foto ou PDF do comprovante</label>
                    <input id="pg-comprovante" type="file" name="comprovante" accept="image/jpeg,image/png,image/webp,application/pdf" @error('comprovante') aria-invalid="true" aria-describedby="pg-comprovante-erro" @enderror>
                    @error('comprovante')<span class="field-error" id="pg-comprovante-erro">{{ $message }}</span>@enderror
                </div>
                <label class="check-linha"><input type="checkbox" name="conferido_extrato" value="1" @checked(old('conferido_extrato'))> Pix: conferi no aplicativo do banco que o valor entrou na conta</label>
            </fieldset>
        @endif
        <button class="button primary pg-enviar" type="submit" data-pg-botao>Confirmar pagamento</button>
    </form>
    @endif

    @if ($podeCombinar && ! $combinadoSit)
    {{-- Parte agora, parte depois: um envio cria o combinado e recebe/cobra a 1ª parcela. --}}
    <form method="POST" action="{{ route('pagamentos.dividido', $ordem) }}" class="pg-painel" data-pg-painel="parte" @if ($modo !== 'parte') hidden @endif>
        @csrf
        <input type="hidden" name="modo" value="parte">
        <h3 class="pg-passo">Agora</h3>
        <div class="fields">
            @include('partials.campo', ['nome' => 'valor_agora', 'rotulo' => 'Quanto o cliente paga agora (R$)', 'id' => 'pg-valor-parte',
                'atributos' => ['inputmode' => 'decimal', 'autocomplete' => 'off', 'placeholder' => $D::paraCampo(intdiv($fin['saldo'], 2)), 'data-pg-agora' => '1'],
                'ajuda' => 'Saldo a receber: '.$D::formatar($fin['saldo']).'.'])
        </div>
        @include('ordens._pg_formas', ['sufixo' => 'parte', 'legenda' => 'Forma de pagamento agora'])
        <h3 class="pg-passo">Depois</h3>
        <p class="pg-restante" data-pg-restante>O restante é o saldo de {{ $D::formatar($fin['saldo']) }} menos o valor pago agora.</p>
        @error('futuras')<p class="field-error" id="futuras-erro" role="alert">{{ $message }}</p>@enderror
        @for ($i = 0; $i < \App\Services\PagamentoDividido::MAX_FUTURAS; $i++)
            @if ($i === 1)<details class="pg-mais" @if (old('futuras.1.vencimento') || old('futuras.2.vencimento')) open @endif><summary>Dividir o restante em mais datas (até 3)</summary>@endif
            <fieldset class="parcela-linha pg-futura" data-pg-futura>
                <legend>{{ $i === 0 ? 'Restante' : 'Restante — data '.($i + 1) }}</legend>
                <div class="field"><label for="pg-fut-venc-{{ $i }}">Data do pagamento</label>
                    <input id="pg-fut-venc-{{ $i }}" name="futuras[{{ $i }}][vencimento]" type="date" min="{{ $hoje }}" value="{{ old("futuras.$i.vencimento") }}" data-pg-venc @error('futuras') aria-describedby="futuras-erro" @enderror></div>
                <div class="field"><label for="pg-fut-valor-{{ $i }}">Valor (R$)</label>
                    <input id="pg-fut-valor-{{ $i }}" name="futuras[{{ $i }}][valor]" inputmode="decimal" autocomplete="off" value="{{ old("futuras.$i.valor") }}" data-pg-valor
                        @if ($i === 0) aria-describedby="pg-fut-valor-0-ajuda" @endif>
                    @if ($i === 0)<p class="hint" id="pg-fut-valor-0-ajuda">Pagando o restante de uma vez, pode deixar em branco: o sistema calcula.</p>@endif</div>
                <div class="field"><label for="pg-fut-meio-{{ $i }}">Como vai pagar</label>
                    <select id="pg-fut-meio-{{ $i }}" name="futuras[{{ $i }}][meio]" data-pg-meio>
                        <option value="">Ainda não sabe</option>
                        @foreach (\App\Models\ParcelaPrevista::MEIOS as $chave => $rotulo)
                            <option value="{{ $chave }}" @selected(old("futuras.$i.meio") === $chave)>{{ $rotulo }}</option>
                        @endforeach
                    </select></div>
            </fieldset>
            @if ($i === \App\Services\PagamentoDividido::MAX_FUTURAS - 1)</details>@endif
        @endfor
        @include('partials.campo', ['nome' => 'observacao', 'rotulo' => 'Observação (opcional, ex.: NSU do cartão)', 'id' => 'pg-obs-parte'])
        <p class="pg-exemplo" data-pg-exemplo aria-live="polite" hidden></p>
        <p class="hint" data-pg-pix-aviso>Se a forma de agora for Pix, a cobrança fica pendente até o banco confirmar; só então a 1ª parcela aparece como paga.</p>
        <button class="button primary pg-enviar" type="submit">Registrar combinado e receber agora</button>
    </form>

    {{-- Tudo depois: combinado do saldo inteiro com data(s); usa o endpoint de combinado existente. --}}
    <form method="POST" action="{{ route('combinados.registrar', $ordem) }}" class="pg-painel" data-pg-painel="depois" @if ($modo !== 'depois') hidden @endif>
        @csrf
        <input type="hidden" name="modo" value="depois">
        <p class="hint">Nada é recebido agora. A soma das datas deve ser <strong>{{ $D::formatar($fin['saldo']) }}</strong>.</p>
        @error('parcelas')<p class="field-error" id="parcelas-erro" role="alert">{{ $message }}</p>@enderror
        @for ($i = 0; $i < 3; $i++)
            <fieldset class="parcela-linha pg-futura">
                <legend>{{ $i === 0 ? 'Pagamento' : 'Pagamento '.($i + 1).' (opcional)' }}</legend>
                <div class="field"><label for="pg-dep-venc-{{ $i }}">Data do pagamento</label>
                    <input id="pg-dep-venc-{{ $i }}" name="parcelas[{{ $i }}][vencimento]" type="date" min="{{ $hoje }}" value="{{ old("parcelas.$i.vencimento") }}" @error('parcelas') aria-describedby="parcelas-erro" @enderror></div>
                <div class="field"><label for="pg-dep-valor-{{ $i }}">Valor (R$)</label>
                    <input id="pg-dep-valor-{{ $i }}" name="parcelas[{{ $i }}][valor]" inputmode="decimal" autocomplete="off" value="{{ old("parcelas.$i.valor", $i === 0 ? $D::paraCampo($fin['saldo']) : '') }}"></div>
                <div class="field"><label for="pg-dep-meio-{{ $i }}">Como vai pagar</label>
                    <select id="pg-dep-meio-{{ $i }}" name="parcelas[{{ $i }}][meio]">
                        <option value="">Ainda não sabe</option>
                        @foreach (\App\Models\ParcelaPrevista::MEIOS as $chave => $rotulo)
                            <option value="{{ $chave }}" @selected(old("parcelas.$i.meio") === $chave)>{{ $rotulo }}</option>
                        @endforeach
                    </select></div>
            </fieldset>
        @endfor
        <button class="button primary pg-enviar" type="submit">Registrar combinado</button>
    </form>
    @endif
</div>
@endif
