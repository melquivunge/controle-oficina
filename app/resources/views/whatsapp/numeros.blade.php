@extends('layouts.app', ['titulo' => 'Números de WhatsApp'])
@section('conteudo')
    <div class="page-head"><div><h1>Números de WhatsApp</h1>
        <p class="subtitle">Números oficiais da empresa ligados à Central. Número central cai em Triagem; número de unidade vai direto para a equipe da unidade.</p></div>
        <div class="page-acoes"><a class="button" href="{{ route('whatsapp.index') }}">Abrir Central</a></div></div>
    @if ($simulado)
        <p class="notice">Modo de simulação: nenhum número envia ou recebe de verdade. O adaptador da API oficial (Meta) existe mas não foi homologado com conta real.</p>
    @endif

    @php($opcoesUnidade = ['' => 'Central (triagem)'] + $unidades->mapWithKeys(fn ($u) => [$u->id => $u->nome])->all())
    @php($provedores = ['simulado' => 'Simulador (demonstração/testes)', 'meta' => 'API oficial do WhatsApp (Meta Cloud API) — não homologado'])
    @php($modos = ['api' => 'Somente API', 'coexistencia' => 'Coexistência (app do celular continua ativo)'])

    @forelse ($numeros as $n)
        <section class="versao wa-numero">
            <header>
                <div><strong>{{ $n->nome }}</strong> <span class="muted">{{ $n->telefoneFormatado() }}</span></div>
                <div class="wa-numero-tags">
                    <span @class(['tag', 'extra' => $n->central()])>{{ $n->rotuloEscopo() }}</span>
                    <span class="tag">{{ $n->provedor === 'meta' ? 'API oficial' : 'Simulador' }}</span>
                    @if ($n->modo_conexao === 'coexistencia')<span class="tag">Coexistência</span>@endif
                    <span @class(['tag', 'off' => ! $n->ativo])>{{ $n->ativo ? 'Ativo' : 'Inativo' }}</span>
                </div>
            </header>
            <p class="hint">{{ $n->conversas_count }} {{ $n->conversas_count === 1 ? 'conversa' : 'conversas' }} · alerta após {{ $n->minutos_alerta }} min sem resposta ·
                credenciais {{ $n->temCredenciais() ? 'salvas (não exibidas)' : 'não informadas' }}@if ($n->provedor === 'meta') · Phone number ID {{ $n->phone_number_id }}@endif</p>
            <details @if (old('_numero') === (string) $n->id) open @endif><summary>Alterar número</summary>
                <form method="POST" action="{{ route('whatsapp.numeros.update', $n) }}">@csrf @method('PUT')
                    <input type="hidden" name="_numero" value="{{ $n->id }}">
                    @include('whatsapp._numero-campos', ['n' => $n, 'p' => 'n'.$n->id.'-'])
                    <label class="opcao-marcar wa-ativo"><input type="checkbox" name="ativo" value="1" @checked(old('_numero') === (string) $n->id ? old('ativo') : $n->ativo)><span>Número ativo (recebe e envia)</span></label>
                    <button class="button primary" type="submit">Salvar alterações</button>
                </form>
            </details>
        </section>
    @empty
        <div class="vazio-cartao"><p>Nenhum número cadastrado. Cadastre o número central (triagem) e/ou os números das unidades.</p></div>
    @endforelse

    <section class="form-panel wa-novo-numero">
        <details class="form-section" @if ($numeros->isEmpty() || (old('_numero') === 'novo')) open @endif>
            <summary>Cadastrar número</summary>
            <form method="POST" action="{{ route('whatsapp.numeros.store') }}">@csrf
                <input type="hidden" name="_numero" value="novo">
                @include('whatsapp._numero-campos', ['n' => null, 'p' => 'novo-'])
                <button class="button primary" type="submit">Cadastrar número</button>
            </form>
        </details>
    </section>
    <section class="helper wa-ajuda">
        <h2>Como funciona</h2>
        <ul>
            <li><strong>Coexistência:</strong> o número continua no app WhatsApp Business do celular e também conectado à API. O que o atendente responde pelo celular aparece na Central como “Enviada pelo celular” e conta como resposta.</li>
            <li><strong>Webhook da API oficial:</strong> {{ url('/webhooks/whatsapp/meta') }} (verificação pelo token de verificação; assinatura X-Hub-Signature-256 com o App secret). Assine também os campos de ecos e histórico para coexistência.</li>
            <li><strong>Modelos aprovados:</strong> um por linha, no formato <code>nome_do_modelo|pt_BR|descrição</code>. Fora da janela de 24 h só eles podem ser enviados.</li>
            <li>Credenciais são guardadas cifradas e nunca exibidas. Deixe em branco para manter as já salvas.</li>
        </ul>
    </section>
@endsection
