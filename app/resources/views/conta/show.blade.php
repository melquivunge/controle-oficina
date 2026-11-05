@extends('layouts.app', ['titulo' => 'Minha conta'])
@section('conteudo')
    <div class="page-head"><div><h1>Minha conta</h1><p class="subtitle">Seu perfil aparece para a equipe em menus, listas e no histórico.</p></div></div>
    <section class="perfil-capa">
        @include('partials.avatar', ['u' => $usuario, 'classe' => 'avatar-xl'])
        <div><h2>{{ $usuario->name }}</h2>
            <p>{{ $usuario->cargo ?: $usuario->papel->rotulo() }} · {{ $usuario->visaoGlobal() ? 'Todas as unidades' : ($usuario->papel === \App\Enums\Papel::Supervisor ? $usuario->unidadesSupervisionadas()->orderBy('nome')->pluck('nome')->join(', ') : $usuario->unidade?->nome) }}</p>
            <p class="muted">{{ $usuario->email }}@if ($usuario->telefone) · {{ \App\Support\Normaliza::telefoneExibicao($usuario->telefone) }}@endif</p></div>
    </section>
    <div class="form-grid">
        <div>
            <form method="POST" action="{{ route('conta.perfil') }}" class="form-panel" enctype="multipart/form-data" style="margin-bottom:20px">
                @csrf @method('PUT')
                <section class="form-section">
                    <h2>Perfil</h2>
                    <p class="section-copy">Nome, telefone, cargo e foto são seus. E-mail de acesso, papel e unidade são definidos pelo proprietário.</p>
                    <div class="foto-campo">
                        @include('partials.avatar', ['u' => $usuario, 'classe' => 'avatar-g'])
                        <div class="field"><label for="foto">Foto (JPG, PNG ou WEBP, até 2 MB)</label>
                            <input id="foto" name="foto" type="file" accept="image/jpeg,image/png,image/webp" @error('foto') aria-invalid="true" aria-describedby="foto-erro" @enderror>
                            @error('foto')<span class="field-error" id="foto-erro">{{ $message }}</span>@enderror
                            @if ($usuario->foto_caminho)<label class="check-linha"><input type="checkbox" name="remover_foto" value="1"> Remover foto atual</label>@endif
                        </div>
                    </div>
                    <div class="fields">
                        @include('partials.campo', ['nome' => 'name', 'rotulo' => 'Nome completo', 'valorAtual' => $usuario->name, 'atributos' => ['autocomplete' => 'name', 'required' => 'required']])
                        @include('partials.campo', ['nome' => 'cargo', 'rotulo' => 'Cargo ou função (opcional)', 'valorAtual' => $usuario->cargo, 'atributos' => ['placeholder' => 'Ex.: Recepcionista, Mecânico líder']])
                        @include('partials.campo', ['nome' => 'telefone', 'rotulo' => 'Telefone com DDD (opcional)', 'valorAtual' => $usuario->telefone ? \App\Support\Normaliza::telefoneExibicao($usuario->telefone) : '', 'atributos' => ['inputmode' => 'tel', 'autocomplete' => 'tel']])
                        <div class="field"><label for="email-leitura">E-mail de acesso</label><input id="email-leitura" type="email" value="{{ $usuario->email }}" disabled></div>
                    </div>
                    <div class="aviso-whatsapp-campo">
                        <label class="check-linha" for="receber_avisos_whatsapp"><input type="checkbox" id="receber_avisos_whatsapp" name="receber_avisos_whatsapp" value="1" @checked(old('receber_avisos_whatsapp', $usuario->receber_avisos_whatsapp)) @error('receber_avisos_whatsapp') aria-invalid="true" aria-describedby="avisos-erro" @enderror> Receber avisos de serviço pelo WhatsApp</label>
                        <p class="hint">Quando um serviço for atribuído a você ou autorizado, chega uma mensagem no telefone acima com OS, veículo, serviço e entrega prevista — sem valores nem contato do cliente. Exige telefone com DDD.</p>
                        @error('receber_avisos_whatsapp')<span class="field-error" id="avisos-erro">{{ $message }}</span>@enderror
                    </div>
                </section>
                <div class="form-footer"><span class="hint">Alterações ficam registradas na auditoria.</span><button class="button primary" type="submit">Salvar perfil</button></div>
            </form>

            @php($empresaAtual = \App\Models\Empresa::atual())
            @php($padraoEmpresa = \App\Support\Aparencia::opcoes()[$empresaAtual->tema_padrao ?? 'claro'] ?? 'Claro')
            <form method="POST" action="{{ route('conta.tema') }}" class="form-panel" id="aparencia" style="margin-bottom:20px">
                @csrf @method('PUT')
                <section class="form-section">
                    <h2>Aparência</h2>
                    <p class="section-copy">Vale só para você, em qualquer aparelho em que entrar.</p>
                    <fieldset class="tema-opcoes">
                        <legend class="sr-only">Tema</legend>
                        @foreach (['' => 'Padrão da empresa ('.$padraoEmpresa.')'] + \App\Support\Aparencia::opcoes() as $valor => $nome)
                            {{-- "Padrão da empresa" mostra a miniatura do tema que a empresa usa de fato. --}}
                            @php($chaveAmostra = $valor ?: ($empresaAtual->tema_padrao ?? 'claro'))
                            @php($temaCustom = str_starts_with($chaveAmostra, 'tema:') ? \App\Models\Tema::find((int) substr($chaveAmostra, 5)) : null)
                            <label class="tema-opcao">
                                <input type="radio" name="tema" value="{{ $valor }}" @checked((string) old('tema', $usuario->tema) === (string) $valor)>
                                <span class="tema-amostra tema-amostra-{{ $temaCustom?->base ?? (isset(\App\Support\Aparencia::PADROES[$chaveAmostra]) ? $chaveAmostra : 'claro') }}{{ $valor === '' ? ' tema-amostra-padrao' : '' }}" @if ($temaCustom) style="--amostra-menu:{{ $temaCustom->cor_menu }};--amostra-marca:{{ $temaCustom->cor_marca }}" @endif aria-hidden="true"><i></i><b></b></span>
                                <span>{{ $nome }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                    @error('tema')<span class="field-error">{{ $message }}</span>@enderror
                </section>
                <div class="form-footer"><span class="hint">"Seguir o aparelho" troca entre claro e escuro conforme o celular ou computador.</span><button class="button primary" type="submit">Salvar aparência</button></div>
            </form>

            <form method="POST" action="{{ route('conta.senha') }}" class="form-panel" id="senha">
                @csrf @method('PUT')
                <section class="form-section">
                    <h2>Alterar senha</h2>
                    <p class="section-copy">Mínimo de 10 caracteres, com letras e números. Ao salvar, outras sessões abertas com sua conta são encerradas.</p>
                    <div class="fields">
                        @include('partials.campo', ['nome' => 'senha_atual', 'rotulo' => 'Senha atual', 'tipo' => 'password', 'atributos' => ['autocomplete' => 'current-password']])
                        <div></div>
                        @include('partials.campo', ['nome' => 'password', 'rotulo' => 'Nova senha', 'tipo' => 'password', 'atributos' => ['autocomplete' => 'new-password']])
                        @include('partials.campo', ['nome' => 'password_confirmation', 'rotulo' => 'Repita a nova senha', 'tipo' => 'password', 'atributos' => ['autocomplete' => 'new-password']])
                    </div>
                </section>
                <div class="form-footer"><span></span><button class="button primary" type="submit">Salvar nova senha</button></div>
            </form>
        </div>
        <aside class="form-aside">
            <section class="helper"><h2>Acesso</h2>
                <dl class="detalhes detalhes-compacto">
                    <dt>Papel</dt><dd>{{ $usuario->papel->rotulo() }}</dd>
                    <dt>Unidade</dt><dd>{{ $usuario->visaoGlobal() ? 'Todas' : ($usuario->papel === \App\Enums\Papel::Supervisor ? $usuario->unidadesSupervisionadas()->orderBy('nome')->pluck('nome')->join(', ') : $usuario->unidade?->nome) }}</dd>
                    <dt>Duas etapas</dt><dd>{{ $usuario->two_factor_confirmed_at ? 'Ativa desde '.\App\Support\Fuso::exibir($usuario->two_factor_confirmed_at, 'd/m/Y') : 'Não configurada' }}</dd>
                </dl></section>
            <section class="helper"><h2>Senha esquecida?</h2><p>Peça ao proprietário para redefinir. Não compartilhe sua senha: cada pessoa usa a própria conta.</p></section>
        </aside>
    </div>
@endsection
