@extends('layouts.app', ['titulo' => 'Unidades'])
@section('conteudo')
    <div class="page-head"><div><h1>Unidades</h1><p class="subtitle">Cada ordem, pagamento e usuário pertence a uma unidade.</p></div><a class="button" href="{{ route('empresa.edit') }}">Identidade da empresa</a></div>
    <section class="form-panel" style="margin-bottom:24px">
        <h2>Nova unidade</h2>
        <form method="POST" action="{{ route('unidades.store') }}" class="inline-form">
            @csrf
            @include('partials.campo', ['nome' => 'codigo', 'rotulo' => 'Código (ex.: ASN)', 'obrigatorio' => true, 'id' => 'nova-codigo'])
            @include('partials.campo', ['nome' => 'nome', 'rotulo' => 'Nome', 'obrigatorio' => true, 'id' => 'nova-nome'])
            <input type="hidden" name="ativa" value="1">
            <button class="button primary" type="submit">Criar unidade</button>
        </form>
    </section>
    @if ($unidades->isEmpty())
        <p class="muted">Nenhuma unidade cadastrada ainda.</p>
    @else
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Código</th><th>Nome</th><th>Usuários</th><th>Situação</th><th><span class="sr-only">Ações</span></th></tr></thead>
            <tbody>
            @foreach ($unidades as $unidade)
                <tr>
                    <td data-label="Código">{{ $unidade->codigo }}</td>
                    <td data-label="Nome">{{ $unidade->nome }}</td>
                    <td data-label="Usuários">{{ $unidade->usuarios_count }}</td>
                    <td data-label="Situação"><span @class(['tag', 'off' => ! $unidade->ativa])>{{ $unidade->ativa ? 'Ativa' : 'Inativa' }}</span></td>
                    <td data-label="Ações">
                        @if ($unidade->ativa)<a class="link" href="{{ route('feriados.index', ['unidade' => $unidade->id]) }}">Feriados locais</a>@endif
                        <details>
                            <summary>Editar</summary>
                            <form method="POST" action="{{ route('unidades.update', $unidade) }}">
                                @csrf @method('PUT')
                                @include('partials.campo', ['nome' => 'codigo', 'rotulo' => 'Código', 'valorAtual' => $unidade->codigo, 'id' => "codigo-{$unidade->id}"])
                                @include('partials.campo', ['nome' => 'nome', 'rotulo' => 'Nome', 'valorAtual' => $unidade->nome, 'id' => "nome-{$unidade->id}"])
                                <input type="hidden" name="ativa" value="0">
                                <label><input type="checkbox" name="ativa" value="1" @checked($unidade->ativa)> Unidade ativa</label>
                                <button class="button" type="submit">Salvar</button>
                            </form>
                        </details>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
@endsection
