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
            @if ($historicoAntigo->isNotEmpty())
                {{-- OS do sistema antigo (09/11/2026): só consulta; não entram em painel, caixa, numeração nem relatórios. --}}
                @php($dinheiro = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.'))
                @php($data = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d/m/Y') : '—')
                @php($idsOs = \Illuminate\Support\Facades\DB::table('legado_registros')->where('cliente_id', $cliente->id)->where('tabela', 'TBL_OS')->pluck('id', 'codigo'))
                <section class="legado historico-antigo" style="margin-top:28px">
                    <div class="section-head"><h2>Histórico no sistema antigo</h2><small>{{ $historicoAntigo->count() }} {{ $historicoAntigo->count() === 1 ? 'OS' : 'OS' }} · só consulta</small></div>
                    @foreach ($historicoAntigo as $h)
                        @php($o = $h['os'])
                        @php($situacao = ($o['CANCELADA'] ?? '') === 'S' ? 'Cancelada' : (($o['STATUS'] ?? '') === 'F' ? 'Finalizada' : 'Aberta'))
                        <details>
                            <summary>OS {{ $o['CODIGO'] }} · {{ $data($o['DATAIN'] ?? null) }}@if ($h['placa']) · {{ $h['placa']->placaFormatada() }}@endif
                                @can('ver_valores') · {{ $dinheiro($o['TOTAL'] ?? 0) }}@endcan · <span @class(['tag', 'off' => $situacao === 'Cancelada'])>{{ $situacao }}</span></summary>
                            <dl class="legado-campos">
                                @if ($h['placa'])<dt>Veículo</dt><dd>{{ $h['placa']->placaFormatada() }} {{ $h['placa']->descricao() }}</dd>@endif
                                @if (! empty($o['KM']))<dt>KM</dt><dd>{{ $o['KM'] }}</dd>@endif
                                <dt>Entrada / saída</dt><dd>{{ $data($o['DATAIN'] ?? null) }} / {{ $data($o['DATAOUT'] ?? null) }}</dd>
                                @can('ver_valores')<dt>Pago</dt><dd>{{ ($o['PAG'] ?? '') === 'S' ? 'Sim'.(! empty($o['DATAPAG']) ? ' em '.$data($o['DATAPAG']) : '') : 'Não' }}</dd>@endcan
                                @foreach (['DESCRICAO' => 'Descrição', 'OBS' => 'Observações'] as $k => $rot)@if (! empty($o[$k]))<dt>{{ $rot }}</dt><dd style="white-space:pre-line">{{ $o[$k] }}</dd>@endif @endforeach
                            </dl>
                            @if (isset($idsOs[$o['CODIGO']]))<p><a class="link" href="{{ route('historico.show', $idsOs[$o['CODIGO']]) }}">Abrir OS completa →</a></p>@endif
                            @if ($h['itens'])
                                <ul class="historico-itens">
                                    @foreach ($h['itens'] as $i)
                                        <li>{{ $i['tipo'] }}: {{ trim($i['DESCRICAO'] ?? '') ?: ($i['_servico'] ?? $i['_produto'] ?? 'item '.($i['CODIGO'] ?? '')) }}
                                            · {{ rtrim(rtrim((string) ($i['QTD'] ?? '1'), '0'), '.') ?: '1' }}×
                                            @can('ver_valores') · {{ $dinheiro($i['PRECO'] ?? 0) }}@endcan</li>
                                    @endforeach
                                </ul>
                            @endif
                        </details>
                    @endforeach
                </section>
            @endif
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
                        <p>Mesmo telefone ou mesmo nome em outro cadastro. Unificar traz para esta ficha atendimentos, veículos, histórico antigo e os dados que faltam aqui; nada é apagado.</p>
                        <form method="POST" action="{{ route('clientes.mesclar', $cliente) }}">
                            @csrf
                            @include('partials.campo', ['nome' => 'duplicado_id', 'rotulo' => 'Cadastro a unificar', 'tipo' => 'select', 'opcoes' => $possiveisDuplicados->mapWithKeys(fn ($d) => [$d->id => $d->nome.' (#'.$d->id.') · '.($d->telefoneFormatado() ?: 'sem telefone').($d->documentoFormatado() ? ' · '.$d->documentoFormatado() : '')])->all()])
                            @include('partials.campo', ['nome' => 'motivo', 'rotulo' => 'Motivo'])
                            <button class="button" type="submit">Unificar cadastros</button>
                        </form>
                    </section>
                @endif
            @endcan
        </aside>
    </div>
@endsection
