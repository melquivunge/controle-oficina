@extends('layouts.app', ['titulo' => $tela])
@section('conteudo')
    {{-- Primeiro acesso: telas que trabalham por unidade explicam o que falta em vez de "Acesso negado". --}}
    <div class="page-head"><div><h1>{{ $tela }}</h1></div></div>
    <section class="cartao">
        <h2>Nenhuma unidade cadastrada</h2>
        <p>Esta tela funciona por unidade (oficina). Cadastre pelo menos uma unidade ativa para usar {{ mb_strtolower($tela) }}.</p>
        @can('configurar')
            <a class="button primary" href="{{ route('unidades.index') }}">Cadastrar unidade</a>
        @else
            <p class="hint">Peça ao proprietário para cadastrar a unidade ou vincular a sua conta a ela.</p>
        @endcan
    </section>
@endsection
