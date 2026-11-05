<div class="form-grid">
    <form class="form-panel" wire:submit="salvar" novalidate>
        <section class="form-section">
            <h2>Cliente e veículo (ou item)</h2>
            <p class="section-copy">Só o essencial. Se o cliente já veio antes, o cadastro aparece ao digitar telefone ou placa.</p>
            <div class="fields">
                @if ($unidades->count() > 1)
                    @include('livewire.partials.campo', ['nome' => 'unidade_id', 'rotulo' => 'Unidade', 'tipo' => 'select', 'opcoes' => ['' => 'Escolha a unidade'] + $unidades->pluck('nome', 'id')->all()])
                @endif
                @include('livewire.partials.campo', ['nome' => 'telefone', 'rotulo' => 'Telefone com DDD', 'tipo' => 'tel', 'live' => true, 'atributos' => ['inputmode' => 'tel', 'autocomplete' => 'off']])
                @unless ($sem_veiculo)
                    @include('livewire.partials.campo', ['nome' => 'placa', 'rotulo' => 'Placa', 'live' => true, 'atributos' => ['autocomplete' => 'off', 'style' => 'text-transform:uppercase']])
                @endunless
                @include('livewire.partials.campo', ['nome' => 'nome', 'rotulo' => 'Nome do cliente', 'atributos' => ['autocomplete' => 'off']])
                @unless ($sem_veiculo)
                    @include('livewire.partials.campo', ['nome' => 'modelo', 'rotulo' => 'Modelo do veículo (opcional)'])
                @endunless
            </div>
            <label class="check-linha sem-veiculo-opcao"><input type="checkbox" wire:model.live="sem_veiculo"> Sem veículo (peças avulsas) — o cliente trouxe só o item, sem placa</label>
            @if ($sem_veiculo)
                <div class="fields">
                    @include('livewire.partials.campo', ['nome' => 'item_descricao', 'rotulo' => 'Descrição do item', 'classe' => 'full', 'atributos' => ['placeholder' => 'Ex.: Jogo de rodas aro 17 — 4 unidades', 'maxlength' => 200, 'autocomplete' => 'off']])
                </div>
            @endif
            @can('ver_contato_cliente')
                <details class="endereco-entrada" @if ($errors->has('endereco.*')) open @endif>
                    <summary>Endereço do cliente (opcional)</summary>
                    <div class="fields">
                        @include('livewire.partials.campo', ['nome' => 'endereco.cep', 'id' => 'endereco-cep', 'rotulo' => 'CEP', 'atributos' => ['inputmode' => 'numeric', 'autocomplete' => 'postal-code', 'maxlength' => 9]])
                        @include('livewire.partials.campo', ['nome' => 'endereco.logradouro', 'id' => 'endereco-logradouro', 'rotulo' => 'Logradouro', 'classe' => 'full'])
                        @include('livewire.partials.campo', ['nome' => 'endereco.numero', 'id' => 'endereco-numero', 'rotulo' => 'Número'])
                        @include('livewire.partials.campo', ['nome' => 'endereco.complemento', 'id' => 'endereco-complemento', 'rotulo' => 'Complemento'])
                        @include('livewire.partials.campo', ['nome' => 'endereco.bairro', 'id' => 'endereco-bairro', 'rotulo' => 'Bairro'])
                        @include('livewire.partials.campo', ['nome' => 'endereco.cidade', 'id' => 'endereco-cidade', 'rotulo' => 'Cidade'])
                        @include('livewire.partials.campo', ['nome' => 'endereco.uf', 'id' => 'endereco-uf', 'rotulo' => 'UF', 'tipo' => 'select', 'opcoes' => ['' => '—'] + array_combine(\App\Support\Endereco::UFS, \App\Support\Endereco::UFS)])
                    </div>
                    <p class="hint">Preenchido aqui, atualiza o endereço no cadastro do cliente.</p>
                </details>
            @endcan

            @if ($cliente_id)
                <p class="alert sucesso" role="status">Usando o cadastro existente de <strong>{{ $nome }}</strong>. <button type="button" class="linklike" wire:click="$set('cliente_id', null)">Não é este cliente</button></p>
            @elseif ($sugestoes->isNotEmpty())
                <div class="notice" role="status" aria-live="polite">
                    <strong>Cadastro encontrado</strong>
                    @foreach ($sugestoes as $s)
                        <p>{{ $s->nome }} · {{ $s->telefoneFormatado() }} · {{ $s->veiculos->map->placaFormatada()->join(', ') ?: 'sem veículos' }}
                            <button type="button" class="button" wire:click="usarCliente({{ $s->id }})">Usar este cadastro</button></p>
                    @endforeach
                </div>
            @endif
            @if ($veiculoExistente && $veiculoExistente->cliente_id !== $cliente_id)
                <div class="notice" role="status">
                    <p>Placa já cadastrada{{ $veiculoExistente->modelo ? ' ('.$veiculoExistente->modelo.')' : '' }} para outro cliente. Se for o mesmo cliente, use “Usar este cadastro”. Se o veículo mudou de dono, confirme a transferência (fica no histórico).</p>
                    <label style="display:flex;gap:8px;align-items:center;font-weight:400"><input type="checkbox" wire:model="confirmar_transferencia"> Confirmo que o veículo mudou de dono</label>
                    @error('confirmar_transferencia')<span class="field-error">{{ $message }}</span>@enderror
                </div>
            @endif
        </section>

        <section class="form-section">
            <h2>Motivo da visita</h2>
            <p class="section-copy">Registre o relato do cliente, mesmo sem diagnóstico.</p>
            <div class="fields">
                @include('livewire.partials.campo', ['nome' => 'motivo', 'rotulo' => 'O que o cliente relatou', 'tipo' => 'textarea', 'classe' => 'full'])
                @include('livewire.partials.campo', ['nome' => 'quilometragem', 'rotulo' => 'Quilometragem (opcional)', 'atributos' => ['inputmode' => 'numeric']])
                @include('livewire.partials.campo', ['nome' => 'observacoes', 'rotulo' => 'Observações (opcional)', 'tipo' => 'textarea', 'classe' => 'full'])
            </div>
            <div class="field full">
                <label for="fotos">Fotos do {{ $sem_veiculo ? 'item' : 'veículo' }} (opcional, até 5)</label>
                <input id="fotos" type="file" wire:model="fotos" multiple accept="image/jpeg,image/png,image/webp" aria-describedby="fotos-ajuda">
                <p class="hint" id="fotos-ajuda">JPG, PNG ou WebP, até 8 MB cada. <span wire:loading wire:target="fotos">Enviando…</span></p>
                @error('fotos')<span class="field-error">{{ $message }}</span>@enderror
                @foreach ($errors->get('fotos.*') as $msgs)<span class="field-error">{{ $msgs[0] }}</span>@endforeach
                @if ($fotos)<p class="hint">{{ count($fotos) }} foto(s) selecionada(s).</p>@endif
            </div>
        </section>

        <details class="form-section" @if($contingencia) open @endif>
            <summary>Registrar atendimento feito durante indisponibilidade do sistema</summary>
            <p class="section-copy">Use somente para regularizar uma entrada anotada no papel. A ordem fica marcada como contingência, com o horário original e você como autor da regularização.</p>
            <label style="display:flex;gap:8px;align-items:center;font-weight:400"><input type="checkbox" wire:model.live="contingencia"> Esta entrada é de contingência</label>
            @if ($contingencia)
                <div class="fields">
                    @include('livewire.partials.campo', ['nome' => 'horario_original', 'rotulo' => 'Horário original da entrada', 'tipo' => 'datetime-local'])
                    @include('livewire.partials.campo', ['nome' => 'contingencia_nota', 'rotulo' => 'Referência da anotação em papel', 'classe' => 'full'])
                </div>
            @endif
        </details>

        <div class="form-footer">
            <a class="button" href="{{ route('painel') }}">Cancelar</a>
            <button class="button primary" type="submit" wire:loading.attr="disabled" wire:target="salvar,fotos">
                <span wire:loading.remove wire:target="salvar">Registrar entrada</span>
                <span wire:loading wire:target="salvar">Registrando…</span>
            </button>
        </div>
    </form>
    <aside class="form-aside">
        <section class="helper">
            <h2>O que acontece depois</h2>
            <p>O sistema cria uma ordem numerada desta unidade. O mecânico vê a ordem na fila para diagnóstico.</p>
        </section>
        <section class="helper">
            <h2>Cliente sem cadastro?</h2>
            <p>Basta nome, telefone e placa. Detalhes podem ser completados depois na ficha do cliente.</p>
        </section>
    </aside>
</div>
