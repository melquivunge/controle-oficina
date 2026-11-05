{{-- Escolha da forma de pagamento (radios grandes). Requer $formas, $rotulosForma, $ajudaForma, $formaPadrao, $sufixo, $legenda. --}}
<fieldset class="pg-formas" @error('forma_agora') aria-describedby="pg-forma-erro-{{ $sufixo }}" @enderror>
    <legend>{{ $legenda }}</legend>
    <div class="pg-formas-opcoes">
        @foreach ($formas as $chave => $_)
            <label class="pg-forma">
                <input type="radio" name="forma_agora" value="{{ $chave }}" data-pg-forma @checked($formaPadrao === $chave) required>
                <span><strong>{{ $rotulosForma[$chave] }}</strong><small>{{ $ajudaForma[$chave] }}</small></span>
            </label>
        @endforeach
    </div>
    @error('forma_agora')<span class="field-error" id="pg-forma-erro-{{ $sufixo }}" role="alert">{{ $message }}</span>@enderror
</fieldset>
