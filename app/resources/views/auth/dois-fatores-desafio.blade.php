@extends('layouts.guest', ['titulo' => 'Verificação em duas etapas'])
@section('conteudo')
    <h1>Confirme que é você</h1>
    <p class="subtitle">Digite o código de 6 dígitos do seu aplicativo autenticador.</p>
    <form method="POST" action="{{ route('dois-fatores.desafio') }}">
        @csrf
        @include('partials.campo', ['nome' => 'codigo', 'rotulo' => 'Código', 'autocomplete' => 'one-time-code', 'obrigatorio' => true, 'atributos' => ['inputmode' => 'numeric', 'autofocus' => 'autofocus']])
        <button class="button primary" type="submit">Continuar</button>
    </form>
    <form method="POST" action="{{ route('sair') }}">@csrf<button class="button" type="submit">Sair</button></form>
@endsection
