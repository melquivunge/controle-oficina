@extends('layouts.guest', ['titulo' => 'Entrar'])
@section('conteudo')
    <h1>Entrar</h1>
    <p class="subtitle">Use sua conta individual. Não compartilhe senha.</p>
    @error('email')<p class="alert erro" role="alert" id="email-erro-geral">{{ $message }}</p>@enderror
    <form method="POST" action="{{ url('/entrar') }}" novalidate>
        @csrf
        @include('partials.campo', ['nome' => 'email', 'rotulo' => 'E-mail', 'tipo' => 'email', 'autocomplete' => 'username', 'obrigatorio' => true])
        @include('partials.campo', ['nome' => 'password', 'rotulo' => 'Senha', 'tipo' => 'password', 'autocomplete' => 'current-password', 'obrigatorio' => true])
        <button class="button primary" type="submit">Entrar</button>
    </form>
@endsection
