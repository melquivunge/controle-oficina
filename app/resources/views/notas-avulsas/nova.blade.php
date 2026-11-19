@extends('layouts.app', ['titulo' => 'Nova nota avulsa'])
@section('conteudo')
    <div class="page-head"><div><h1>Nova nota avulsa (peças)</h1>
        <p class="subtitle">NF-e de venda ou de remessa sem ordem de serviço. A nota sai com o certificado da empresa e entra na mesma numeração das NF-e.</p></div></div>

    <section class="form-panel" style="margin-bottom:20px"><section class="form-section">
        <h2>1. Cliente (destinatário)</h2>
        @if ($cliente)
            <p><strong>{{ $cliente->nome }}</strong> · CPF/CNPJ {{ $cliente->documento ?: 'não informado' }}<br>
                <span class="muted">{{ $cliente->enderecoTexto() ?: 'Endereço não informado' }}</span></p>
            @if ($falta)@include('partials.cliente-dados-nota', ['cliente' => $cliente, 'falta' => $falta])@endif
            <p><a href="{{ route('notas-avulsas.create') }}">Escolher outro cliente</a></p>
        @else
            <form method="GET" class="filters">
                @include('partials.campo', ['nome' => 'q', 'rotulo' => 'Nome, telefone ou placa do cliente', 'valorAtual' => $termo, 'atributos' => ['minlength' => 3, 'autofocus' => 'autofocus']])
                <button class="button" type="submit">Buscar</button>
            </form>
            @if ($termo !== '')
                @forelse ($encontrados as $c)
                    <p><a class="button" href="{{ route('notas-avulsas.create', ['cliente' => $c->id]) }}">Usar</a> <strong>{{ $c->nome }}</strong>
                        <span class="muted">{{ $c->telefoneFormatado() }} · {{ $c->cidade ?: 'sem cidade' }}{{ $c->uf ? '/'.$c->uf : '' }}</span></p>
                @empty
                    <p class="muted">Nenhum cliente encontrado.</p>
                @endforelse
            @endif
            @can('registrar_entrada')
            <details class="form-panel" style="margin-top:12px" @if (request()->boolean('novo') || old('para_nota')) open @endif>
                <summary><strong>Cliente novo? Cadastrar aqui</strong> (não precisa de veículo)</summary>
                <form method="POST" action="{{ route('clientes.store') }}" style="margin-top:12px">@csrf
                    <input type="hidden" name="para_nota" value="1">
                    <p class="hint">A NF-e exige CPF ou CNPJ e o endereço completo do cliente (rua, número, bairro, cidade, UF e CEP).</p>
                    <div class="fields">
                        @include('partials.campo', ['nome' => 'nome', 'rotulo' => 'Nome completo', 'id' => 'nc-nome', 'obrigatorio' => true])
                        @include('partials.campo', ['nome' => 'documento', 'rotulo' => 'CPF ou CNPJ', 'id' => 'nc-documento', 'obrigatorio' => true, 'atributos' => ['inputmode' => 'numeric']])
                        @include('partials.campo', ['nome' => 'inscricao_estadual', 'rotulo' => 'Inscrição estadual (empresa contribuinte de ICMS)', 'id' => 'nc-ie', 'atributos' => ['inputmode' => 'numeric'],
                            'ajuda' => 'Loja/empresa com IE: obrigatória, senão a Sefaz recusa (232). Pessoa física: deixe em branco.'])
                        @include('partials.campo', ['nome' => 'telefone', 'rotulo' => 'Telefone com DDD', 'id' => 'nc-telefone', 'tipo' => 'tel', 'obrigatorio' => true])
                        @include('partials.campo', ['nome' => 'email', 'rotulo' => 'E-mail (opcional)', 'id' => 'nc-email', 'tipo' => 'email'])
                    </div>
                    @include('partials.endereco-campos', ['valores' => null, 'idPrefixo' => 'nc'])
                    @error('telefone')
                        <label class="check-linha"><input type="checkbox" name="confirmar_telefone" value="1"> É outra pessoa com o mesmo telefone: cadastrar mesmo assim</label>
                    @enderror
                    <div class="form-footer"><span></span><button class="button primary" type="submit">Cadastrar e continuar a nota</button></div>
                </form>
            </details>
            @endcan
        @endif
    </section></section>

    @if ($cliente && ! $falta)
        @php($fiscal = \App\Support\DadosFiscais::atuais())
        <form method="POST" action="{{ route('notas-avulsas.store') }}" class="form-panel">@csrf
            <input type="hidden" name="cliente_id" value="{{ $cliente->id }}">
            <section class="form-section"><h2>2. Operação</h2><div class="fields">
                @include('partials.campo', ['nome' => 'operacao', 'rotulo' => 'Tipo de nota', 'tipo' => 'select', 'obrigatorio' => true, 'valorAtual' => old('operacao', 'venda'),
                    'opcoes' => ['venda' => "Venda (CFOP {$fiscal['nfe_cfop_dentro']} / {$fiscal['nfe_cfop_fora']}, CSOSN {$fiscal['nfe_csosn']})",
                        'remessa' => "Remessa (CFOP {$fiscal['nfe_remessa_cfop_dentro']} / {$fiscal['nfe_remessa_cfop_fora']}, CSOSN {$fiscal['nfe_remessa_csosn']})"]])
                @include('partials.campo', ['nome' => 'canal', 'rotulo' => 'Como foi a venda', 'tipo' => 'select', 'obrigatorio' => true, 'valorAtual' => old('canal', 'mercado_livre'),
                    'opcoes' => \App\Models\NotaAvulsa::CANAIS])
                @include('partials.campo', ['nome' => 'unidade_id', 'rotulo' => 'Unidade', 'tipo' => 'select', 'obrigatorio' => true, 'valorAtual' => old('unidade_id'),
                    'opcoes' => $unidades->pluck('nome', 'id')->all()])
                @include('partials.campo', ['nome' => 'frete', 'rotulo' => 'Frete', 'tipo' => 'select', 'obrigatorio' => true, 'valorAtual' => old('frete', 9),
                    'opcoes' => \App\Models\NotaAvulsa::FRETES])
                @include('partials.campo', ['nome' => 'pagamento', 'rotulo' => 'Forma de pagamento', 'tipo' => 'select', 'obrigatorio' => true, 'valorAtual' => old('pagamento', '17'),
                    'opcoes' => \App\Models\NotaAvulsa::PAGAMENTOS])
            </div>
                <p class="hint">O CFOP sai sozinho: começa com 5 para cliente do DF e com 6 para cliente de outro estado ({{ $cliente->uf ?: 'UF não informada' }}). Os códigos ficam em Configurações › Dados fiscais.</p>
            </section>
            <section class="form-section"><h2>3. Peças</h2>
                <p class="hint">Escolha a peça do catálogo (preço e NCM vêm do catálogo) ou escreva a descrição. Linhas vazias são ignoradas.</p>
                @error('itens')<p class="alert erro" role="alert">{{ $message }}</p>@enderror
                <div class="table-wrap"><table class="data-table">
                    <thead><tr><th>Peça do catálogo</th><th>ou descrição</th><th>NCM</th><th>Qtd.</th><th>Preço unitário (R$)</th></tr></thead>
                    <tbody>
                    @for ($k = 0; $k < $linhas; $k++)
                        <tr>
                            <td data-label="Peça do catálogo"><select name="itens[{{ $k }}][catalogo_item_id]" aria-label="Peça do catálogo, linha {{ $k + 1 }}">
                                <option value="">—</option>
                                @foreach ($catalogo as $c)<option value="{{ $c->id }}" @selected((string) old("itens.$k.catalogo_item_id") === (string) $c->id)>{{ $c->nome }} · {{ \App\Support\Dinheiro::formatar($c->preco_centavos) }}</option>@endforeach
                            </select></td>
                            <td data-label="Descrição"><input name="itens[{{ $k }}][descricao]" maxlength="120" value="{{ old("itens.$k.descricao") }}" aria-label="Descrição, linha {{ $k + 1 }}"></td>
                            <td data-label="NCM"><input name="itens[{{ $k }}][ncm]" inputmode="numeric" maxlength="8" size="9" value="{{ old("itens.$k.ncm") }}" aria-label="NCM, linha {{ $k + 1 }}"></td>
                            <td data-label="Qtd."><input name="itens[{{ $k }}][quantidade]" inputmode="decimal" size="5" value="{{ old("itens.$k.quantidade") }}" placeholder="1" aria-label="Quantidade, linha {{ $k + 1 }}"></td>
                            <td data-label="Preço unitário"><input name="itens[{{ $k }}][preco]" inputmode="decimal" size="10" value="{{ old("itens.$k.preco") }}" placeholder="do catálogo" aria-label="Preço unitário, linha {{ $k + 1 }}"></td>
                        </tr>
                        @foreach (["itens.$k.descricao", "itens.$k.preco", "itens.$k.ncm"] as $campoErro)@error($campoErro)<tr><td colspan="5"><span class="field-error">{{ $message }}</span></td></tr>@enderror @endforeach
                    @endfor
                    </tbody>
                </table></div>
            </section>
            <section class="form-section"><h2>4. Observação (opcional)</h2>
                @include('partials.campo', ['nome' => 'observacao', 'rotulo' => 'Observação na nota', 'valorAtual' => old('observacao'), 'atributos' => ['maxlength' => 500],
                    'ajuda' => 'Ex.: pedido do Mercado Livre nº 2000…, enviado pelos Correios (Sedex).'])
            </section>
            @error('cliente_id')<p class="alert erro" role="alert">{{ $message }}</p>@enderror
            @error('canal')<p class="alert erro" role="alert">{{ $message }}</p>@enderror
            <div class="form-footer"><a class="button" href="{{ route('notas-avulsas.index') }}">Cancelar</a>
                <button class="button primary" type="submit" data-confirmar="Emitir a NF-e agora? Depois de autorizada, só dá para cancelar.">Emitir NF-e</button></div>
        </form>
    @endif
@endsection
