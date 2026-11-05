@extends('layouts.guest', ['titulo' => 'Confirmar senha'])
@section('conteudo')
    <h1>Confirme sua senha</h1>
    <p class="subtitle">Esta área altera configurações sensíveis. Por segurança, confirme sua senha para continuar.</p>
    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf
        @include('partials.campo', ['nome' => 'password', 'rotulo' => 'Senha', 'tipo' => 'password', 'autocomplete' => 'current-password', 'obrigatorio' => true, 'atributos' => ['autofocus' => 'autofocus']])
        <button class="button primary" type="submit">Confirmar</button>
    </form>
    <a class="button" href="{{ route('painel') }}" style="margin-top:10px">Voltar ao início</a>
@endsection
