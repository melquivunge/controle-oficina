@php($novo = ! $usuario->exists)
@extends('layouts.app', ['titulo' => $novo ? 'Novo usuário' : $usuario->name])
@section('conteudo')
    <div class="page-head"><div><h1>{{ $novo ? 'Novo usuário' : $usuario->name }}</h1>
        <p class="subtitle">{{ $novo ? 'Crie uma conta individual para cada pessoa.' : 'Alterações ficam registradas no histórico.' }}</p></div></div>
    <div class="form-grid">
    <form class="form-panel" method="POST" action="{{ $novo ? route('usuarios.store') : route('usuarios.update', $usuario) }}" novalidate>
        @csrf @unless($novo) @method('PUT') @endunless
        <section class="form-section">
            <h2>Identificação</h2>
            <div class="fields">
                @include('partials.campo', ['nome' => 'name', 'rotulo' => 'Nome completo', 'valorAtual' => $usuario->name, 'obrigatorio' => true])
                @include('partials.campo', ['nome' => 'email', 'rotulo' => 'E-mail de acesso', 'tipo' => 'email', 'valorAtual' => $usuario->email, 'obrigatorio' => true])
            </div>
        </section>
        <section class="form-section">
            <h2>Acesso</h2>
            <div class="fields">
                @include('partials.campo', ['nome' => 'papel', 'rotulo' => 'Papel', 'tipo' => 'select', 'opcoes' => collect(\App\Enums\Papel::cases())->mapWithKeys(fn ($p) => [$p->value => $p->rotulo()])->all(), 'valorAtual' => $usuario->papel?->value, 'obrigatorio' => true])
                @include('partials.campo', ['nome' => 'unidade_id', 'rotulo' => 'Unidade', 'tipo' => 'select', 'opcoes' => ['' => 'Nenhuma (somente proprietário)'] + $unidades->pluck('nome', 'id')->all(), 'valorAtual' => $usuario->unidade_id, 'ajuda' => 'Obrigatória para gerente, recepção e mecânico. Para o supervisor, é a unidade principal.'])
            </div>
            @php($supervisionadas = array_map('intval', old('unidades', $usuario->exists ? $usuario->unidadesSupervisionadas()->pluck('unidades.id')->all() : [])))
            <fieldset class="unidades-supervisor" style="border:0;padding:0;margin-top:16px">
                <legend>Unidades supervisionadas</legend>
                <p class="hint">Somente para o papel Supervisor: ele terá a visão de gerente em cada unidade marcada (a principal é incluída automaticamente).</p>
                <div class="checks">
                    @foreach ($unidades as $un)
                        <label><input type="checkbox" name="unidades[]" value="{{ $un->id }}" @checked(in_array($un->id, $supervisionadas, true))> {{ $un->nome }}</label>
                    @endforeach
                </div>
                @error('unidades')<span class="field-error">{{ $message }}</span>@enderror
                @error('unidades.*')<span class="field-error">{{ $message }}</span>@enderror
            </fieldset>
            <fieldset style="border:0;padding:0;margin-top:16px">
                <legend>Permissões financeiras adicionais</legend>
                <p class="hint">Gerente e proprietário já possuem todas. Conceda à recepção apenas se necessário.</p>
                <div class="checks">
                    @foreach (\App\Enums\Papel::PERMISSOES_CONCEDIVEIS as $chave => $rotulo)
                        <label><input type="checkbox" name="permissoes_extras[]" value="{{ $chave }}" @checked(in_array($chave, old('permissoes_extras', $usuario->permissoes_extras ?? []), true))> {{ $rotulo }}</label>
                    @endforeach
                </div>
                @error('permissoes_extras.*')<span class="field-error">{{ $message }}</span>@enderror
            </fieldset>
            @unless ($usuario->exists && $usuario->is(auth()->user()))
            <fieldset style="border:0;padding:0;margin-top:16px">
                <legend>Verificação em duas etapas</legend>
                <p class="hint">Obrigatória para proprietários. Dispense só para quem não consegue usar o aplicativo de códigos no celular: a conta fica protegida apenas pela senha.</p>
                <label class="opcao-marcar"><input type="hidden" name="dois_fatores_dispensado" value="0"><input type="checkbox" name="dois_fatores_dispensado" value="1" @checked(old('dois_fatores_dispensado', $usuario->dois_fatores_dispensado))> <span>Dispensar a verificação em duas etapas para esta pessoa</span></label>
            </fieldset>
            @endunless
        </section>
        <section class="form-section">
            <h2>{{ $novo ? 'Senha inicial' : 'Redefinir senha (opcional)' }}</h2>
            <div class="fields">
                @include('partials.campo', ['nome' => 'password', 'rotulo' => 'Senha', 'tipo' => 'password', 'autocomplete' => 'new-password', 'obrigatorio' => $novo, 'ajuda' => 'Mínimo de 10 caracteres, com letras e números.'])
                @include('partials.campo', ['nome' => 'password_confirmation', 'rotulo' => 'Repita a senha', 'tipo' => 'password', 'autocomplete' => 'new-password', 'obrigatorio' => $novo])
            </div>
        </section>
        <div class="form-footer">
            <a class="button" href="{{ route('usuarios.index') }}">Cancelar</a>
            <button class="button primary" type="submit">{{ $novo ? 'Criar conta' : 'Salvar alterações' }}</button>
        </div>
    </form>
    @unless ($novo || $usuario->is(auth()->user()))
    <aside class="form-aside">
        <section class="helper">
            <h2>{{ $usuario->ativo ? 'Desativar conta' : 'Reativar conta' }}</h2>
            <p>{{ $usuario->ativo ? 'A pessoa perde o acesso imediatamente, inclusive em sessões abertas. O histórico é preservado.' : 'A pessoa volta a acessar com a senha atual.' }}</p>
            <form method="POST" action="{{ route('usuarios.ativo', $usuario) }}">
                @csrf
                @include('partials.campo', ['nome' => 'motivo', 'rotulo' => 'Motivo', 'obrigatorio' => true])
                <button class="button" type="submit">{{ $usuario->ativo ? 'Desativar acesso' : 'Reativar acesso' }}</button>
            </form>
        </section>
        @if ($usuario->exigeDoisFatores())
        <section class="helper">
            <h2>Verificação em duas etapas</h2>
            <p>Celular perdido ou trocado? Gere uma nova chave. A anterior deixa de funcionar.</p>
            <form method="POST" action="{{ route('usuarios.dois-fatores', $usuario) }}">
                @csrf
                @include('partials.campo', ['nome' => 'motivo', 'rotulo' => 'Motivo', 'obrigatorio' => true, 'id' => 'motivo-2fa'])
                <button class="button" type="submit">Gerar nova chave</button>
            </form>
        </section>
        @endif
    </aside>
    @endunless
    </div>
@endsection
