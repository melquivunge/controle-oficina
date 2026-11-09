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
                    <p><span class="plate">{{ $v->placaFormatada() }}</span> {{ $v->descricao() }}</p>
                @endforeach
            </section>
            @can('ver_contato_cliente')
                @if ($cliente->legado)
                    {{-- Importação do sistema antigo (09/11/2026): registro original completo, só leitura. --}}
                    <section class="legado" style="margin-top:28px">
                        <div class="section-head"><h2>Dados do sistema antigo</h2><small>Importado do SGA · código {{ $cliente->legado_codigo }}</small></div>
                        @if ($cliente->documentoFormatado())<p><strong>{{ strlen($cliente->documento) === 11 ? 'CPF' : 'CNPJ' }}:</strong> {{ $cliente->documentoFormatado() }}</p>@endif
                        <details><summary>Ver todos os campos do cadastro antigo</summary>
                            <dl class="legado-campos">
                                @foreach (collect($cliente->legado)->except('_veiculos_nao_importados') as $campo => $valor)
                                    <dt>{{ $campo }}</dt><dd>{{ is_array($valor) ? json_encode($valor, JSON_UNESCAPED_UNICODE) : $valor }}</dd>
                                @endforeach
                            </dl>
                        </details>
                        @foreach ($cliente->veiculos->whereNotNull('legado') as $v)
                            <details><summary>Veículo {{ $v->placaFormatada() }}: todos os campos antigos</summary>
                                <dl class="legado-campos">@foreach ($v->legado as $campo => $valor)<dt>{{ $campo }}</dt><dd>{{ $valor }}</dd>@endforeach</dl>
                            </details>
                        @endforeach
                        @foreach ($cliente->legado['_veiculos_nao_importados'] ?? [] as $v)
                            <details><summary>Veículo {{ $v['PLACA'] ?? 'sem placa' }} (não importado como veículo: placa já em uso ou inválida)</summary>
                                <dl class="legado-campos">@foreach ($v as $campo => $valor)<dt>{{ $campo }}</dt><dd>{{ $valor }}</dd>@endforeach</dl>
                            </details>
                        @endforeach
                    </section>
                @endif
            @endcan
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
