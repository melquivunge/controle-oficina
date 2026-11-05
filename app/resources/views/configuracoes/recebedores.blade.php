@extends('layouts.app', ['titulo' => 'Contas de recebimento'])
@section('conteudo')
    <div class="page-head"><div><h1>Contas oficiais de recebimento</h1><p class="subtitle">Todas as cobranças Pix da unidade vão para a conta ativa. Atendentes e gerentes não podem alterá-la.</p></div></div>
    @if (config('oficina.integracoes') === 'simulado')
        <p class="notice">Modo de simulação: as contas abaixo são fictícias e nenhuma cobrança real é feita. A integração real depende da escolha e homologação do provedor.</p>
    @endif
    @foreach ($unidades as $unidade)
        @php($ativa = $unidade->recebedores->firstWhere('ativo', true))
        <section class="versao">
            <header><strong>{{ $unidade->nome }}</strong><span @class(['tag', 'off' => ! $ativa])>{{ $ativa ? 'Conta ativa: '.$ativa->descricao : 'Sem conta configurada' }}</span></header>
            @if ($ativa)<p class="hint">Identificação no provedor: {{ $ativa->identificador_conta }} · desde {{ \App\Support\Fuso::exibir($ativa->created_at) }}</p>@endif
            <details><summary>{{ $ativa ? 'Trocar conta oficial' : 'Configurar conta oficial' }}</summary>
                <form method="POST" action="{{ route('recebedores.store') }}">
                    @csrf
                    <input type="hidden" name="unidade_id" value="{{ $unidade->id }}">
                    <div class="fields">
                        @include('partials.campo', ['nome' => 'descricao', 'rotulo' => 'Descrição (ex.: Conta PJ Asa Norte)', 'id' => 'desc-'.$unidade->id])
                        @include('partials.campo', ['nome' => 'identificador_conta', 'rotulo' => 'Identificação da conta no provedor', 'id' => 'conta-'.$unidade->id])
                    </div>
                    <button class="button primary" type="submit">Salvar conta oficial</button>
                </form>
            </details>
        </section>
    @endforeach
@endsection
