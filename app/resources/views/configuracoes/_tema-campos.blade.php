{{-- Campos de um tema personalizado (criar/editar). Erros na bolsa própria do formulário. --}}
@php($e = $errors->getBag($bag))
@php($usarOld = $e->any())
<div class="fields">
    @foreach (['nome' => 'Nome do tema'] as $campo => $rotulo)
        <div class="field"><label for="{{ $prefixo }}-nome">{{ $rotulo }}</label>
            <input id="{{ $prefixo }}-nome" name="nome" maxlength="40" required value="{{ $usarOld ? old('nome') : $tema?->nome }}" @if($e->has('nome')) aria-invalid="true" aria-describedby="{{ $prefixo }}-nome-erro" @endif>
            @if ($e->has('nome'))<span class="field-error" id="{{ $prefixo }}-nome-erro">{{ $e->first('nome') }}</span>@endif</div>
    @endforeach
    @php($base = $usarOld ? old('base') : ($tema?->base ?? 'claro'))
    <fieldset class="field tema-base"><legend>Base</legend>
        <label class="check-linha"><input type="radio" name="base" value="claro" @checked($base === 'claro')> Clara</label>
        <label class="check-linha"><input type="radio" name="base" value="escuro" @checked($base === 'escuro')> Escura</label>
        @if ($e->has('base'))<span class="field-error">{{ $e->first('base') }}</span>@endif
    </fieldset>
    @foreach (['cor_marca' => ['Cor da marca', 'Botões principais e destaques', '#1f9d6b'], 'cor_menu' => ['Cor do menu', 'Fundo do menu lateral', '#0f1b2d']] as $campo => [$rotulo, $ajuda, $padrao])
        <div class="field"><label for="{{ $prefixo }}-{{ $campo }}">{{ $rotulo }}</label>
            <input class="campo-cor" id="{{ $prefixo }}-{{ $campo }}" name="{{ $campo }}" type="color" required value="{{ $usarOld ? old($campo) : ($tema?->$campo ?? $padrao) }}" aria-describedby="{{ $prefixo }}-{{ $campo }}-ajuda @if($e->has($campo)){{ $prefixo }}-{{ $campo }}-erro @endif" @if($e->has($campo)) aria-invalid="true" @endif>
            <p class="hint" id="{{ $prefixo }}-{{ $campo }}-ajuda">{{ $ajuda }}</p>
            @if ($e->has($campo))<span class="field-error" id="{{ $prefixo }}-{{ $campo }}-erro">{{ $e->first($campo) }}</span>@endif</div>
    @endforeach
</div>
