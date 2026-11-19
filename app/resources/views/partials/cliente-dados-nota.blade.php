{{-- Completar dados do cliente para a nota sem sair da tela (19/11/2026). Requer $cliente e $falta (mensagem do que falta). --}}
@can('ver_contato_cliente')
<details class="form-panel cliente-dados-nota" open style="margin:12px 0">
    <summary><strong>Completar dados do cliente para a nota</strong> <span class="muted">— {{ $falta }}</span></summary>
    <form method="POST" action="{{ route('clientes.dados-nota', $cliente) }}" style="margin-top:12px">@csrf @method('PUT')
        <div class="fields">
            @include('partials.campo', ['nome' => 'documento', 'rotulo' => 'CPF ou CNPJ', 'id' => 'dn-documento', 'obrigatorio' => true, 'valorAtual' => $cliente->documento, 'atributos' => ['inputmode' => 'numeric']])
            @include('partials.campo', ['nome' => 'inscricao_estadual', 'rotulo' => 'Inscrição estadual (empresa contribuinte de ICMS)', 'id' => 'dn-ie', 'valorAtual' => $cliente->inscricao_estadual,
                'atributos' => ['inputmode' => 'numeric'], 'ajuda' => 'Pessoa física: deixe em branco.'])
        </div>
        @include('partials.endereco-campos', ['valores' => $cliente, 'idPrefixo' => 'dn'])
        <div class="form-footer"><span class="hint">Fica salvo na ficha do cliente.</span><button class="button primary" type="submit">Salvar e voltar para a nota</button></div>
    </form>
</details>
@else
    <p class="alert erro" role="alert">{{ $falta }} Peça a quem cuida do cadastro para completar a ficha do cliente.</p>
@endcan
