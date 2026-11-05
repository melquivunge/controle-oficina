@extends('layouts.app', ['titulo' => 'Minha fila'])
@section('conteudo')
    <div class="page-head"><div><h1>Minha fila</h1><p class="subtitle">Ordens atribuídas a você e veículos aguardando diagnóstico.</p></div></div>
    <section style="margin-bottom:32px">
        <div class="section-head"><h2>Comigo</h2><small>{{ $minhas->count() }}</small></div>
        @include('ordens._lista', ['ordens' => $minhas, 'vazio' => 'Nenhuma ordem atribuída a você no momento.'])
    </section>
    <section>
        <div class="section-head"><h2>Aguardando diagnóstico sem responsável</h2><small>{{ $livres->count() }}</small></div>
        @include('ordens._lista', ['ordens' => $livres, 'vazio' => 'Nenhum veículo aguardando diagnóstico.'])
    </section>
@endsection
