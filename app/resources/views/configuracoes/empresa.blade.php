@extends('layouts.app', ['titulo' => 'Identidade da empresa'])
@section('conteudo')
    <div class="page-head"><div><h1>Identidade da empresa</h1><p class="subtitle">Nome, logo e aparência do sistema, e os dados impressos na ordem de serviço. Unidades com CNPJ ou endereço próprios usam os dados da unidade.</p></div></div>
    <div class="form-grid">
        <div>
            <form method="POST" action="{{ route('empresa.update') }}" class="form-panel" enctype="multipart/form-data" style="margin-bottom:24px">
                @csrf @method('PUT')
                <section class="form-section">
                    <h2>Sistema</h2>
                    <p class="section-copy">Como o sistema se apresenta para a equipe: no menu, na aba do navegador e na tela de entrada.</p>
                    <div class="fields">
                        @include('partials.campo', ['nome' => 'nome_sistema', 'rotulo' => 'Nome do sistema (opcional)', 'valorAtual' => $empresa->nome_sistema, 'ajuda' => 'Vazio usa "'.\App\Models\Empresa::NOME_PADRAO.'".', 'atributos' => ['maxlength' => 60, 'placeholder' => \App\Models\Empresa::NOME_PADRAO]])
                        @include('partials.campo', ['nome' => 'tema_padrao', 'rotulo' => 'Tema padrão', 'tipo' => 'select', 'valorAtual' => $empresa->tema_padrao ?? 'claro', 'opcoes' => $opcoesTema])
                        <div class="field full">
                            <label for="descricao">Descrição da empresa (opcional)</label>
                            <textarea id="descricao" name="descricao" maxlength="300" rows="3" aria-describedby="descricao-ajuda @error('descricao') descricao-erro @enderror" @error('descricao') aria-invalid="true" @enderror>{{ old('descricao', $empresa->descricao) }}</textarea>
                            <p class="hint" id="descricao-ajuda">Até 300 caracteres. Aparece na tela de entrada.</p>
                            @error('descricao')<span class="field-error" id="descricao-erro">{{ $message }}</span>@enderror
                        </div>
                    </div>
                </section>
                <section class="form-section">
                    <h2>Empresa</h2>
                    <div class="fields">
                        @include('partials.campo', ['nome' => 'razao_social', 'rotulo' => 'Razão social', 'valorAtual' => $empresa->razao_social, 'obrigatorio' => true])
                        @include('partials.campo', ['nome' => 'nome_fantasia', 'rotulo' => 'Nome fantasia', 'valorAtual' => $empresa->nome_fantasia, 'obrigatorio' => true])
                        @include('partials.campo', ['nome' => 'cnpj', 'rotulo' => 'CNPJ', 'valorAtual' => $empresa->cnpj ? $empresa->cnpjFormatado() : '', 'obrigatorio' => true, 'atributos' => ['autocomplete' => 'off', 'maxlength' => 18]])
                        @include('partials.campo', ['nome' => 'telefone', 'rotulo' => 'Telefone com DDD', 'valorAtual' => $empresa->telefone ? $empresa->telefoneFormatado() : '', 'obrigatorio' => true, 'atributos' => ['inputmode' => 'tel']])
                        @include('partials.campo', ['nome' => 'email', 'rotulo' => 'E-mail (opcional)', 'tipo' => 'email', 'valorAtual' => $empresa->email])
                        @include('partials.campo', ['nome' => 'cor_principal', 'rotulo' => 'Cor principal (opcional)', 'valorAtual' => $empresa->cor_principal, 'ajuda' => 'Formato #RRGGBB. Usada em títulos e linhas da OS impressa.', 'atributos' => ['placeholder' => '#1f4e79', 'maxlength' => 7]])
                    </div>
                </section>
                <section class="form-section">
                    <h2>Endereço</h2>
                    @include('partials.endereco-campos', ['valores' => $empresa, 'idPrefixo' => 'empresa'])
                </section>
                <section class="form-section">
                    <h2>Logo</h2>
                    <div class="logo-campo">
                        @if ($empresa->logo_caminho)<img src="{{ route('empresa.logo') }}" alt="Logo atual" class="logo-previa">@endif
                        <div class="field"><label for="logo">Arquivo (JPG, PNG ou WebP, até 1 MB; SVG não é aceito)</label>
                            <input id="logo" name="logo" type="file" accept="image/jpeg,image/png,image/webp" @error('logo') aria-invalid="true" aria-describedby="logo-erro" @enderror>
                            @error('logo')<span class="field-error" id="logo-erro">{{ $message }}</span>@enderror
                            @if ($empresa->logo_caminho)<label class="check-linha"><input type="checkbox" name="remover_logo" value="1"> Remover logo atual</label>@endif
                        </div>
                    </div>
                </section>
                <div class="form-footer"><span class="hint">Alterações ficam registradas na auditoria.</span><button class="button primary" type="submit">Salvar identidade</button></div>
            </form>

            <section id="aparencia" class="temas-secao">
                <div class="section-head"><h2>Temas</h2><small>O padrão da empresa é escolhido em "Tema padrão", acima</small></div>
                <ul class="temas-lista temas-prontos">
                    @foreach (\App\Support\Aparencia::PADROES as $chave => $nome)
                        <li class="form-panel tema-item tema-pronto">
                            <span class="tema-amostra tema-amostra-{{ $chave }}" aria-hidden="true"><i></i><b></b></span>
                            <strong>{{ $nome }}</strong> <span class="muted">Tema pronto{{ "" }}@if (($empresa->tema_padrao ?? 'claro') === $chave) · padrão da empresa @endif</span>
                        </li>
                    @endforeach
                </ul>
                <h3 class="temas-subtitulo">Personalizados</h3>
                @if ($temas->isNotEmpty())
                    <ul class="temas-lista">
                        @foreach ($temas as $tema)
                            @php($bag = 'tema'.$tema->id)
                            <li>
                                <details class="form-panel tema-item" @if($errors->getBag($bag)->any()) open @endif>
                                    <summary>
                                        <span class="tema-amostra tema-amostra-{{ $tema->base }}" style="--amostra-menu:{{ $tema->cor_menu }};--amostra-marca:{{ $tema->cor_marca }}" aria-hidden="true"><i></i><b></b></span>
                                        <strong>{{ $tema->nome }}</strong> <span class="muted">Base {{ $tema->base === 'claro' ? 'clara' : 'escura' }}@if ($empresa->tema_padrao === $tema->chave()) · padrão da empresa @endif</span>
                                    </summary>
                                    <form method="POST" action="{{ route('temas.update', $tema) }}">
                                        @csrf @method('PUT')
                                        @include('configuracoes._tema-campos', ['tema' => $tema, 'bag' => $bag, 'prefixo' => 't'.$tema->id])
                                        <div class="form-footer"><span></span><button class="button" type="submit">Salvar tema</button></div>
                                    </form>
                                    <form method="POST" action="{{ route('temas.destroy', $tema) }}" class="tema-excluir">
                                        @csrf @method('DELETE')
                                        <button class="button perigo" type="submit" data-confirmar="Excluir o tema {{ $tema->nome }}? Quem usa este tema volta ao padrão da empresa.">Excluir tema</button>
                                    </form>
                                </details>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <details class="form-panel tema-item" @if($errors->getBag('tema')->any() || $temas->isEmpty()) open @endif>
                    <summary><strong>Novo tema</strong> <span class="muted">Escolha a base e duas cores</span></summary>
                    <form method="POST" action="{{ route('temas.store') }}">
                        @csrf
                        @include('configuracoes._tema-campos', ['tema' => null, 'bag' => 'tema', 'prefixo' => 'novo'])
                        <div class="form-footer"><span class="hint">O texto sobre as cores fica branco ou preto automaticamente, o que for mais legível.</span><button class="button primary" type="submit">Criar tema</button></div>
                    </form>
                </details>
            </section>

            <section>
                <div class="section-head"><h2>Dados próprios por unidade</h2><small>Opcional · vazio usa os da empresa</small></div>
                @foreach ($unidades as $unidade)
                    @php($bag = 'unidade'.$unidade->id)
                    <details class="form-panel unidade-identidade" @if($errors->getBag($bag)->any()) open @endif>
                        <summary><strong>{{ $unidade->nome }}</strong> <span class="muted">{{ $unidade->cnpj ? \App\Rules\Cnpj::exibicao($unidade->cnpj) : 'CNPJ da empresa' }}</span></summary>
                        <form method="POST" action="{{ route('empresa.unidade.update', $unidade) }}">
                            @csrf @method('PUT')
                            <div class="fields">
                                @foreach (['cnpj' => ['CNPJ da unidade', $unidade->cnpj ? \App\Rules\Cnpj::exibicao($unidade->cnpj) : ''], 'telefone' => ['Telefone da unidade', $unidade->telefone ? \App\Support\Normaliza::telefoneExibicao($unidade->telefone) : '']] as $campo => [$rotulo, $valor])
                                    <div class="field"><label for="u{{ $unidade->id }}-{{ $campo }}">{{ $rotulo }}</label>
                                        <input id="u{{ $unidade->id }}-{{ $campo }}" name="{{ $campo }}" value="{{ $valor }}" @if($errors->getBag($bag)->has($campo)) aria-invalid="true" aria-describedby="u{{ $unidade->id }}-{{ $campo }}-erro" @endif>
                                        @if ($errors->getBag($bag)->has($campo))<span class="field-error" id="u{{ $unidade->id }}-{{ $campo }}-erro">{{ $errors->getBag($bag)->first($campo) }}</span>@endif</div>
                                @endforeach
                            </div>
                            @include('partials.endereco-campos', ['valores' => $unidade, 'idPrefixo' => 'u'.$unidade->id, 'bag' => $bag, 'usarOld' => false])
                            <div class="form-footer"><span></span><button class="button" type="submit">Salvar dados da unidade</button></div>
                        </form>
                    </details>
                @endforeach
            </section>
        </div>
        <aside class="form-aside">
            <section class="helper"><h2>Tema</h2><p>O tema padrão vale para quem não escolheu outro em Minha conta. Cada pessoa pode trocar o próprio tema a qualquer momento.</p></section>
            <section class="helper"><h2>Onde aparece</h2><p>Nome do sistema, logo e descrição: menu e tela de entrada. Na OS impressa (e na versão do cliente pelo link): logo, nome fantasia, CNPJ, telefone e endereço. A OS impressa não é documento fiscal.</p></section>
            <section class="helper"><h2>CNPJ</h2><p>Os dígitos verificadores são conferidos. O formato alfanumérico (novas inscrições a partir de julho/2026) também é aceito.</p></section>
        </aside>
    </div>
@endsection
