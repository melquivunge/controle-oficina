{{-- Marca: logo da empresa quando cadastrada; senão o ícone padrão. Decorativa (o nome vem ao lado). --}}
@if ($empresa->logo_caminho)
    <span class="brand-mark brand-mark-logo" aria-hidden="true"><img src="{{ route('empresa.logo', ['v' => $empresa->updated_at?->timestamp]) }}" alt=""></span>
@else
    <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 18V8l9-5 9 5v10M7 21V11h10v10M3 15h18"/></svg></span>
@endif
