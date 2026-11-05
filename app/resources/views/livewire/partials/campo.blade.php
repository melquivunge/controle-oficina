{{-- Campo Livewire com label e erro associado. --}}
@php($id = $id ?? $nome)
@php($modelo = ($live ?? false) ? 'wire:model.live.debounce.400ms' : 'wire:model')
<div class="field {{ $classe ?? '' }}">
    <label for="{{ $id }}">{{ $rotulo }}</label>
    @if (($tipo ?? 'text') === 'select')
        <select id="{{ $id }}" {{ $modelo }}="{{ $nome }}" @error($nome) aria-invalid="true" aria-describedby="{{ $id }}-erro" @enderror>
            @foreach ($opcoes as $valor => $texto)<option value="{{ $valor }}">{{ $texto }}</option>@endforeach
        </select>
    @elseif (($tipo ?? 'text') === 'textarea')
        <textarea id="{{ $id }}" {{ $modelo }}="{{ $nome }}" rows="3" @error($nome) aria-invalid="true" aria-describedby="{{ $id }}-erro" @enderror></textarea>
    @else
        <input id="{{ $id }}" type="{{ $tipo ?? 'text' }}" {{ $modelo }}="{{ $nome }}" @foreach ($atributos ?? [] as $a => $v) {{ $a }}="{{ $v }}" @endforeach
               @error($nome) aria-invalid="true" aria-describedby="{{ $id }}-erro" @enderror>
    @endif
    @error($nome)<span class="field-error" id="{{ $id }}-erro">{{ $message }}</span>@enderror
</div>
