@extends('layouts.app', ['titulo' => 'Novo orçamento sem entrada'])
@section('conteudo')
    <div class="page-head"><div><h1>Novo orçamento sem entrada</h1>
        <p class="subtitle">O carro ainda não está na oficina. O número sai da mesma sequência das OS da unidade.</p></div></div>
    <form method="POST" action="{{ route('orcamentos-avulsos.store') }}" class="form-panel">@csrf
        <div class="form-section">
            <h2>Cliente</h2>
            @if ($conversa)
                <input type="hidden" name="conversa_id" value="{{ $conversa->id }}">
                @if ($conversa->cliente)
                    <p>{{ $conversa->cliente->nome }} <span class="muted">· conversa do WhatsApp</span></p>
                    <p class="hint">O orçamento será enviado nesta conversa.</p>
                @else
                    <p class="alert erro" role="alert">Esta conversa ainda não tem cliente vinculado. Volte à conversa e cadastre ou vincule o cliente.</p>
                @endif
            @else
                <div class="fields">
                    @include('partials.campo', ['nome' => 'telefone', 'rotulo' => 'Telefone com DDD', 'tipo' => 'tel', 'obrigatorio' => true, 'atributos' => ['inputmode' => 'tel', 'autocomplete' => 'off']])
                    @include('partials.campo', ['nome' => 'nome', 'rotulo' => 'Nome do cliente', 'obrigatorio' => true, 'atributos' => ['autocomplete' => 'off'],
                        'ajuda' => 'Se o telefone já for de um cliente cadastrado, o orçamento fica no cadastro dele.'])
                </div>
            @endif
            @error('cliente')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <div class="form-section">
            <h2>Veículo (opcional)</h2>
            <div class="fields">
                @include('partials.campo', ['nome' => 'placa', 'rotulo' => 'Placa', 'atributos' => ['autocomplete' => 'off', 'style' => 'text-transform:uppercase']])
                @include('partials.campo', ['nome' => 'veiculo_descricao', 'rotulo' => 'Veículo ou item', 'atributos' => ['placeholder' => 'Ex.: Jeep Compass 2024 · jogo de rodas aro 18', 'maxlength' => 120]])
                @if ($unidades->count() > 1)
                    @include('partials.campo', ['nome' => 'unidade_id', 'rotulo' => 'Unidade', 'tipo' => 'select', 'opcoes' => ['' => 'Escolha a unidade'] + $unidades->pluck('nome', 'id')->all(),
                        'valorAtual' => $conversa?->unidade_id])
                @else
                    <input type="hidden" name="unidade_id" value="{{ $unidades->first()?->id }}">
                @endif
            </div>
        </div>
        <button class="button primary" type="submit" @disabled($conversa && ! $conversa->cliente)>Criar e incluir os itens</button>
    </form>
@endsection
