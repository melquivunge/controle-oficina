{{-- Campo com label e erro associado (aria-describedby). --}}
@php($id = $id ?? str_replace(['[', ']'], ['_', ''], $nome))
<div class="field {{ $classe ?? '' }}">
    <label for="{{ $id }}">{{ $rotulo }}</label>
    @if (($tipo ?? 'text') === 'select')
        <select id="{{ $id }}" name="{{ $nome }}" @error($nome) aria-invalid="true" aria-describedby="{{ $id }}-erro" @enderror {{ ($obrigatorio ?? false) ? 'required' : '' }}>
            @foreach ($opcoes as $valor => $texto)
                <option value="{{ $valor }}" @selected((string) old($nome, $valorAtual ?? '') === (string) $valor)>{{ $texto }}</option>
            @endforeach
        </select>
    @else
        <input id="{{ $id }}" name="{{ $nome }}" type="{{ $tipo ?? 'text' }}" value="{{ ($tipo ?? 'text') === 'password' ? '' : old($nome, $valorAtual ?? '') }}"
               @if(isset($autocomplete)) autocomplete="{{ $autocomplete }}" @endif
               @if(isset($ajuda)) aria-describedby="{{ $id }}-ajuda @error($nome){{ $id }}-erro @enderror" @else @error($nome) aria-describedby="{{ $id }}-erro" @enderror @endif
               @error($nome) aria-invalid="true" @enderror {{ ($obrigatorio ?? false) ? 'required' : '' }} @foreach ($atributos ?? [] as $attr => $val) {{ $attr }}="{{ $val }}" @endforeach>
    @endif
    @isset($ajuda)<p class="hint" id="{{ $id }}-ajuda">{{ $ajuda }}</p>@endisset
    @error($nome)<span class="field-error" id="{{ $id }}-erro">{{ $message }}</span>@enderror
</div>
