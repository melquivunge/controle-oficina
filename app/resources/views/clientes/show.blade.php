@extends('layouts.app', ['titulo' => $cliente->nome])
@section('conteudo')
    <div class="page-head"><div><h1>{{ $cliente->nome }}</h1><p class="subtitle">@can('ver_contato_cliente'){{ $cliente->telefoneFormatado() }} · @endcan{{ $cliente->veiculos->count() }} {{ $cliente->veiculos->count() === 1 ? 'veículo' : 'veículos' }}@can('ver_contato_cliente')@if (\App\Support\Endereco::preenchido($cliente))<br>{{ $cliente->enderecoTexto() }}@endif @endcan</p></div></div>
    <div class="form-grid">
        <div>
            <section style="margin-bottom:28px">
                <div class="section-head"><h2>Atendimentos</h2><small>{{ auth()->user()->visaoGlobal() ? 'Todas as unidades' : (count(auth()->user()->unidadeIds()) > 1 ? 'Somente das suas unidades' : 'Somente da sua unidade') }}</small></div>
                @include('ordens._lista', ['ordens' => $ordens, 'vazio' => 'Nenhum atendimento visível para você.'])
            </section>
            <section>
                <div class="section-head"><h2>Veículos</h2></div>
                @foreach ($cliente->veiculos as $v)
                    <p><span class="plate">{{ $v->placaFormatada() }}</span> {{ $v->modelo }}</p>
                @endforeach
            </section>
        </div>
        <aside class="form-aside">
            <section class="helper">
                <h2>Dados de contato</h2>
                <form method="POST" action="{{ route('clientes.update', $cliente) }}">
                    @csrf @method('PUT')
                    @include('partials.campo', ['nome' => 'nome', 'rotulo' => 'Nome', 'valorAtual' => $cliente->nome])
                    @include('partials.campo', ['nome' => 'telefone', 'rotulo' => 'Telefone com DDD', 'tipo' => 'tel', 'valorAtual' => $cliente->telefone])
                    @include('partials.campo', ['nome' => 'email', 'rotulo' => 'E-mail (opcional)', 'tipo' => 'email', 'valorAtual' => $cliente->email])
                    @can('ver_contato_cliente')
                        <details class="endereco-cliente" @if(\App\Support\Endereco::preenchido($cliente) || $errors->hasAny(\App\Support\Endereco::CAMPOS)) open @endif>
                            <summary>Endereço (opcional)</summary>
                            @include('partials.endereco-campos', ['valores' => $cliente, 'idPrefixo' => 'cliente'])
                        </details>
                    @endcan
                    <button class="button" type="submit">Salvar dados</button>
                </form>
            </section>
            @can('mesclar_clientes')
                @if ($possiveisDuplicados->isNotEmpty())
                    <section class="helper">
                        <h2>Possível cadastro duplicado</h2>
                        <p>Mesmo telefone em outro cadastro. Unificar move atendimentos e veículos para esta ficha; nada é apagado.</p>
                        <form method="POST" action="{{ route('clientes.mesclar', $cliente) }}">
                            @csrf
                            @include('partials.campo', ['nome' => 'duplicado_id', 'rotulo' => 'Cadastro a unificar', 'tipo' => 'select', 'opcoes' => $possiveisDuplicados->mapWithKeys(fn ($d) => [$d->id => $d->nome.' (#'.$d->id.')'])->all()])
                            @include('partials.campo', ['nome' => 'motivo', 'rotulo' => 'Motivo'])
                            <button class="button" type="submit">Unificar cadastros</button>
                        </form>
                    </section>
                @endif
            @endcan
        </aside>
    </div>
@endsection
