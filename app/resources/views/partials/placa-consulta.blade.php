{{-- Placa com "Buscar dados do veículo" (mesma consulta da Nova entrada). Preenche marca/modelo/ano/cor vazios do mesmo formulário. --}}
@php($disponivel = ! app(\App\Integracoes\Placa\ConsultaPlaca::class) instanceof \App\Integracoes\Placa\ConsultaPlacaIndisponivel)
<div class="field consulta-placa" @if ($disponivel) data-consulta-placa="{{ route('placas.consulta') }}" @endif>
    <label for="{{ $id }}">Placa</label>
    <div class="consulta-placa__linha">
        <input id="{{ $id }}" name="placa" type="text" value="{{ old('placa') }}" autocomplete="off" style="text-transform:uppercase"
               @error('placa') aria-invalid="true" aria-describedby="{{ $id }}-erro" @enderror>
        @if ($disponivel)<button type="button" class="button" data-consulta-placa-botao>Buscar dados do veículo</button>@endif
    </div>
    <p class="hint" data-consulta-placa-aviso role="status"></p>
    @error('placa')<span class="field-error" id="{{ $id }}-erro">{{ $message }}</span>@enderror
</div>
