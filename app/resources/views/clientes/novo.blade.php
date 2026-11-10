@extends('layouts.app', ['titulo' => 'Novo cliente'])
@section('conteudo')
    {{-- Cadastro só do cliente, sem abrir OS (09/11/2026). Para receber o carro hoje, use Nova entrada. --}}
    <div class="page-head"><div><h1>Novo cliente</h1><p class="subtitle">Cadastra o cliente sem abrir ordem de serviço. Para receber o carro agora, use <a href="{{ route('entradas.nova') }}">Nova entrada</a>.</p></div></div>
    <form method="POST" action="{{ route('clientes.store') }}" class="form-panel" novalidate>
        @csrf
        <section class="form-section">
            <h2>Cliente</h2>
            <div class="fields">
                @include('partials.campo', ['nome' => 'nome', 'rotulo' => 'Nome', 'obrigatorio' => true, 'autocomplete' => 'off'])
                @include('partials.campo', ['nome' => 'telefone', 'rotulo' => 'Telefone com DDD', 'tipo' => 'tel', 'obrigatorio' => true, 'atributos' => ['inputmode' => 'tel']])
                @include('partials.campo', ['nome' => 'documento', 'rotulo' => 'CPF ou CNPJ (opcional)', 'atributos' => ['inputmode' => 'numeric']])
                @include('partials.campo', ['nome' => 'email', 'rotulo' => 'E-mail (opcional)', 'tipo' => 'email'])
            </div>
            @error('telefone')
                @if (str_contains($message, 'já é de'))
                    <label class="check-linha"><input type="checkbox" name="confirmar_telefone" value="1"> É outra pessoa com o mesmo telefone (família ou empresa): cadastrar mesmo assim</label>
                @endif
            @enderror
            @can('ver_contato_cliente')
                <details class="endereco-cliente" @if ($errors->hasAny(\App\Support\Endereco::CAMPOS)) open @endif>
                    <summary>Adicionar endereço (opcional)</summary>
                    @include('partials.endereco-campos', ['valores' => null, 'idPrefixo' => 'novo'])
                </details>
            @endcan
            <div class="fields">@include('partials.campo', ['nome' => 'observacoes', 'rotulo' => 'Observações (opcional)', 'classe' => 'full'])</div>
        </section>
        <section class="form-section">
            <h2>Veículo (opcional)</h2>
            <div class="fields">
                @include('partials.placa-consulta', ['id' => 'placa'])
                @include('partials.campo', ['nome' => 'marca', 'rotulo' => 'Marca'])
                @include('partials.campo', ['nome' => 'modelo', 'rotulo' => 'Modelo'])
                @include('partials.campo', ['nome' => 'ano', 'rotulo' => 'Ano', 'atributos' => ['inputmode' => 'numeric', 'maxlength' => 4]])
                @include('partials.campo', ['nome' => 'cor', 'rotulo' => 'Cor'])
            </div>
        </section>
        <section class="form-section form-acoes-fim"><button class="button primary" type="submit">Cadastrar cliente</button><a class="button" href="{{ route('clientes.index') }}">Cancelar</a></section>
    </form>
@endsection
