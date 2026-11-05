@extends('layouts.app', ['titulo' => 'Usuários'])
@section('conteudo')
    <div class="page-head">
        <div><h1>Usuários</h1><p class="subtitle">Contas individuais. Desativar revoga o acesso imediatamente.</p></div>
        <a class="button primary" href="{{ route('usuarios.create') }}"><span class="plus">＋</span> Novo usuário</a>
    </div>
    <form method="GET" class="filters">
        @include('partials.campo', ['nome' => 'unidade', 'rotulo' => 'Unidade', 'tipo' => 'select', 'opcoes' => ['' => 'Todas'] + $unidades->pluck('nome', 'id')->all(), 'valorAtual' => request('unidade')])
        <button class="button" type="submit">Filtrar</button>
    </form>
    @if ($usuarios->isEmpty())
        <p class="muted">Nenhum usuário encontrado.</p>
    @else
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Nome</th><th>Papel</th><th>Unidade</th><th>Situação</th><th><span class="sr-only">Ações</span></th></tr></thead>
            <tbody>
            @foreach ($usuarios as $u)
                <tr>
                    <td data-label="Nome"><span class="pessoa">@include('partials.avatar', ['u' => $u])<span><strong>{{ $u->name }}</strong><small>{{ $u->email }}@if ($u->cargo) · {{ $u->cargo }}@endif</small></span></span></td>
                    <td data-label="Papel">{{ $u->papel->rotulo() }}</td>
                    <td data-label="Unidade">{{ $u->unidade?->nome ?? 'Todas' }}</td>
                    <td data-label="Situação"><span @class(['tag', 'off' => ! $u->ativo])>{{ $u->ativo ? 'Ativa' : 'Desativada' }}</span></td>
                    <td data-label="Ações"><a class="button" href="{{ route('usuarios.edit', $u) }}">Abrir</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    {{ $usuarios->links() }}
    @endif
@endsection
