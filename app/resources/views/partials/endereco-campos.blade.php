{{-- Campos de endereço opcionais. Parâmetros: $valores (model ou null), $idPrefixo, $bag (bag de erros), $usarOld (bool). --}}
@php($erros = $errors->getBag($bag ?? 'default'))
@php($usarOld = $usarOld ?? true)
@php($rotulos = ['cep' => 'CEP', 'logradouro' => 'Logradouro', 'numero' => 'Número', 'complemento' => 'Complemento', 'bairro' => 'Bairro', 'cidade' => 'Cidade', 'uf' => 'UF'])
<div class="fields endereco-campos">
    @foreach ($rotulos as $campo => $rotulo)
        @php($id = ($idPrefixo ?? 'end').'-'.$campo)
        @php($atual = $valores?->getAttribute($campo))
        @php($valor = $usarOld ? old($campo, $atual) : $atual)
        <div @class(['field', 'full' => $campo === 'logradouro', 'curto' => in_array($campo, ['numero', 'uf', 'cep'], true)])>
            <label for="{{ $id }}">{{ $rotulo }}</label>
            @if ($campo === 'uf')
                <select id="{{ $id }}" name="uf" @if($erros->has('uf')) aria-invalid="true" aria-describedby="{{ $id }}-erro" @endif>
                    <option value="">—</option>
                    @foreach (\App\Support\Endereco::UFS as $uf)<option value="{{ $uf }}" @selected($valor === $uf)>{{ $uf }}</option>@endforeach
                </select>
            @else
                <input id="{{ $id }}" name="{{ $campo }}" value="{{ $valor }}" @if($campo === 'cep') inputmode="numeric" autocomplete="postal-code" maxlength="9" @endif
                       @if($erros->has($campo)) aria-invalid="true" aria-describedby="{{ $id }}-erro" @endif>
            @endif
            @if ($erros->has($campo))<span class="field-error" id="{{ $id }}-erro">{{ $erros->first($campo) }}</span>@endif
        </div>
    @endforeach
</div>
