<form wire:submit="salvar" class="orcamento-editor" novalidate>
    @error('itens')<p class="alert erro" role="alert">{{ $message }}</p>@enderror
    @if ($opcoesCatalogo->isNotEmpty())
        <div class="catalogo-escolha">
            <div class="field">
                <label for="catalogo-escolhido">Incluir do catálogo</label>
                <select id="catalogo-escolhido" wire:model="catalogoEscolhido" @error('catalogoEscolhido') aria-invalid="true" aria-describedby="catalogo-escolhido-erro" @enderror>
                    <option value="">Escolha um serviço ou produto…</option>
                    @foreach ($opcoesCatalogo as $categoria => $itensCategoria)
                        <optgroup label="{{ $categoria }}">
                            @foreach ($itensCategoria as $c)
                                <option value="{{ $c->id }}">{{ $c->nome }} — {{ \App\Support\Dinheiro::formatar($c->precoNaUnidade($unidadeId)) }} {{ $c->rotuloUnidade() }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                @error('catalogoEscolhido')<span class="field-error" id="catalogo-escolhido-erro">{{ $message }}</span>@enderror
            </div>
            <button type="button" class="button" wire:click="incluirCatalogo">Incluir</button>
        </div>
    @endif
    <div class="table-wrap">
        <table class="data-table editor-itens">
            <thead><tr><th>Descrição</th><th>Qtd.</th><th>Preço unitário (R$)</th><th>Total</th><th><span class="sr-only">Remover</span></th></tr></thead>
            <tbody>
            @foreach ($itens as $i => $item)
                <tr wire:key="item-{{ $i }}">
                    <td data-label="Descrição">
                        <label class="rotulo-celular" for="item-{{ $i }}-descricao">Descrição do item {{ $i + 1 }}</label>
                        <input id="item-{{ $i }}-descricao" wire:model.blur="itens.{{ $i }}.descricao" @error("itens.$i.descricao") aria-invalid="true" aria-describedby="item-{{ $i }}-descricao-erro" @enderror>
                        @error("itens.$i.descricao")<span class="field-error" id="item-{{ $i }}-descricao-erro">{{ $message }}</span>@enderror
                        {{-- Tipo fiscal: catálogo define; item livre escolhe (serviço → NFS-e; peça → NF-e). --}}
                        @if ($info[$i]['cat'])
                            <span class="tag">{{ \App\Support\DadosFiscais::TIPOS_ITEM[$info[$i]['cat']->tipo] ?? 'Serviço' }}</span>
                        @else
                            <label class="tipo-item" for="item-{{ $i }}-tipo">Tipo
                                <select id="item-{{ $i }}-tipo" wire:model="itens.{{ $i }}.tipo">@foreach (\App\Support\DadosFiscais::TIPOS_ITEM as $valor => $rotulo)<option value="{{ $valor }}">{{ $rotulo }}</option>@endforeach</select>
                            </label>
                        @endif
                        @if ($info[$i]['cat'])
                            <p class="item-catalogo">
                                <span class="tag">Catálogo</span>
                                Tabela {{ \App\Support\Dinheiro::formatar($info[$i]['tabela']) }} {{ $info[$i]['cat']->rotuloUnidade() }} · máx. {{ str_replace('.', ',', rtrim(rtrim($info[$i]['limite'], '0'), '.')) }}% sem aprovação
                                @if (bccomp($info[$i]['efetivo'], '0', 2) > 0)
                                    · <span @class(['desconto-efetivo', 'acima' => $info[$i]['excede']])>desconto {{ str_replace('.', ',', $info[$i]['efetivo']) }}%{{ $info[$i]['excede'] ? ' — acima do limite' : '' }}</span>
                                @endif
                            </p>
                            @if ($podeDesconto)
                                <label class="desconto-item" for="item-{{ $i }}-desconto">Desc. % sobre a tabela
                                    <input id="item-{{ $i }}-desconto" inputmode="decimal" placeholder="0" wire:model.live.debounce.500ms="itens.{{ $i }}.desconto" @error("itens.$i.desconto") aria-invalid="true" @enderror>
                                </label>
                                @error("itens.$i.desconto")<span class="field-error">{{ $message }}</span>@enderror
                            @endif
                        @elseif ($info[$i]['excede'])
                            <p class="item-catalogo"><span class="desconto-efetivo acima">desconto {{ str_replace('.', ',', $info[$i]['efetivo']) }}% — acima da regra geral</span></p>
                        @endif
                    </td>
                    <td data-label="Qtd.">
                        <label class="rotulo-celular" for="item-{{ $i }}-quantidade">Quantidade do item {{ $i + 1 }}</label>
                        <input id="item-{{ $i }}-quantidade" inputmode="decimal" wire:model.live.debounce.500ms="itens.{{ $i }}.quantidade" style="max-width:90px" @error("itens.$i.quantidade") aria-invalid="true" aria-describedby="item-{{ $i }}-quantidade-erro" @enderror>
                        @error("itens.$i.quantidade")<span class="field-error" id="item-{{ $i }}-quantidade-erro">{{ $message }}</span>@enderror
                    </td>
                    <td data-label="Preço unitário">
                        <label class="rotulo-celular" for="item-{{ $i }}-preco">Preço unitário do item {{ $i + 1 }}</label>
                        <input id="item-{{ $i }}-preco" inputmode="decimal" placeholder="0,00" wire:model.live.debounce.500ms="itens.{{ $i }}.preco" style="max-width:130px" @error("itens.$i.preco") aria-invalid="true" aria-describedby="item-{{ $i }}-preco-erro" @enderror>
                        @error("itens.$i.preco")<span class="field-error" id="item-{{ $i }}-preco-erro">{{ $message }}</span>@enderror
                    </td>
                    <td data-label="Total">{{ $linhas[$i] === null ? '—' : \App\Support\Dinheiro::formatar($linhas[$i]) }}</td>
                    <td data-label="Remover"><button type="button" class="button" wire:click="removerLinha({{ $i }})" aria-label="Remover item {{ $i + 1 }}">Remover</button></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <button type="button" class="button" wire:click="adicionarLinha">＋ Adicionar item livre</button>

    @if ($sugeridos->isNotEmpty())
        <section class="adicionais-sugeridos" aria-labelledby="adicionais-titulo">
            <h4 id="adicionais-titulo">Adicionais sugeridos</h4>
            <ul>
                @foreach ($sugeridos as $a)
                    <li><span>{{ $a->nome }} — {{ \App\Support\Dinheiro::formatar($a->precoNaUnidade($unidadeId)) }} {{ $a->rotuloUnidade() }}</span>
                        <button type="button" class="button" wire:click="incluirCatalogo({{ $a->id }})" aria-label="Incluir {{ $a->nome }}">Incluir</button></li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="fields" style="margin-top:18px">
        @if ($podeDesconto)
            @include('livewire.partials.campo', ['nome' => 'desconto', 'rotulo' => 'Desconto (R$, opcional)', 'live' => true, 'atributos' => ['inputmode' => 'decimal', 'placeholder' => '0,00']])
            @include('livewire.partials.campo', ['nome' => 'desconto_motivo', 'rotulo' => 'Motivo do desconto'])
        @endif
        @if ($avulsoId)
            @include('livewire.partials.campo', ['nome' => 'validade', 'rotulo' => 'Válido até', 'tipo' => 'date', 'atributos' => ['min' => now()->toDateString(), 'max' => now()->addDays(90)->toDateString()]])
        @endif
        @include('livewire.partials.campo', ['nome' => 'observacao', 'rotulo' => 'Observação para o cliente (opcional)', 'tipo' => 'textarea', 'classe' => 'full'])
    </div>
    @if ($avulsoId)<p class="hint">Desconto só na OS, depois da entrada do veículo. Aqui o preço não fica abaixo da tabela.</p>
    @elseif (! $podeDesconto)<p class="hint">Descontos exigem permissão de gerente.</p>@endif
    @if ($acimaDoLimite)
        <p class="alert aviso" role="status">Desconto acima do limite sem aprovação (item do catálogo ou regra geral de {{ str_replace('.', ',', rtrim(rtrim($regraGeral, '0'), '.')) }}%). Ao salvar, o pedido vai para aprovação interna; o orçamento só poderá ser concluído depois de aprovado.</p>
    @endif

    <dl class="totais">
        <dt>Subtotal</dt><dd>{{ \App\Support\Dinheiro::formatar($subtotal) }}</dd>
        <dt>Desconto</dt><dd>{{ \App\Support\Dinheiro::formatar($descontoCentavos) }}</dd>
        <dt>Total</dt><dd><strong>{{ \App\Support\Dinheiro::formatar(max(0, $subtotal - $descontoCentavos)) }}</strong></dd>
    </dl>
    <p class="hint">Totais calculados por item: quantidade × preço, arredondado ao centavo (meio centavo para cima). O valor final é conferido pelo sistema ao salvar.</p>
    <button class="button primary" type="submit" wire:loading.attr="disabled" wire:target="salvar">{{ $avulsoId ? 'Salvar orçamento' : 'Salvar rascunho' }}</button>
</form>
