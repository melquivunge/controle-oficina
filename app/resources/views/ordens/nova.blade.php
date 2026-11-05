@extends('layouts.app', ['titulo' => 'Nova entrada'])
@section('conteudo')
    <div class="page-head"><div><h1>Nova entrada</h1><p class="subtitle">Registre o veículo assim que ele chegar. Isso gera a ordem de serviço.</p></div></div>
    <livewire:nova-entrada />
@endsection
