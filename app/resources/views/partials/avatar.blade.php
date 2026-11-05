{{-- Foto do usuário ou iniciais. Parâmetros: $u (User), $classe (opcional). --}}
@if ($u->foto_caminho)
    <img class="avatar avatar-foto {{ $classe ?? '' }}" src="{{ route('usuarios.foto', ['usuario' => $u, 'v' => substr(md5($u->foto_caminho), 0, 8)]) }}" alt="" width="36" height="36">
@else
    <span class="avatar {{ $classe ?? '' }}" aria-hidden="true">{{ $u->iniciais() }}</span>
@endif
