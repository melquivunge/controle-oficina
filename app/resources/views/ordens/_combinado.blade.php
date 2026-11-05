{{-- Combinado de pagamento do saldo (Bloco B). Incluído por ordens._financeiro; requer $ordem, $u, $fin, $combinadoSit. Mecânico nunca vê. --}}
@if (\App\Services\Combinados::podeVer($u))
    @php($podeCombinar = $u->pode('combinar_pagamento') && $u->papel !== \App\Enums\Papel::Mecanico)
    @php($recebivel = in_array($ordem->status, \App\Enums\StatusOrdem::recebiveis(), true))
    <div class="combinado" id="combinado">
        @foreach (['parcelas', 'motivo_combinado'] as $campoErro)
            @error($campoErro)<p class="alert erro" role="alert">{{ $message }}</p>@enderror
        @endforeach
        @if ($combinadoSit)
            <h3>Pagamento combinado</h3>
            <p class="hint">Registrado por {{ $combinadoSit['combinado']->criadoPor?->name }} em {{ \App\Support\Fuso::exibir($combinadoSit['combinado']->created_at) }} ·
                saldo combinado {{ \App\Support\Dinheiro::formatar((int) $combinadoSit['combinado']->saldo_centavos) }} · já recebido {{ \App\Support\Dinheiro::formatar($combinadoSit['pago']) }}.
                A situação das parcelas vem dos recebimentos confirmados; o caixa só conta o que entrou.</p>
            @if ($combinadoSit['desatualizado'])
                <p class="notice">O saldo mudou desde o combinado (nova versão aprovada ou devolução de recebimento anterior). Ajuste o combinado para cobrir o saldo atual de {{ \App\Support\Dinheiro::formatar($fin['saldo']) }}.</p>
            @endif
            <div class="table-wrap"><table class="data-table parcelas">
                <thead><tr><th>Parcela</th><th>Vencimento</th><th>Valor</th><th>Recebido</th><th>Meio previsto</th><th>Situação</th></tr></thead>
                <tbody>
                @foreach ($combinadoSit['parcelas'] as $p)
                    <tr @class(['parcela-vencida' => $p->situacao === 'vencida'])>
                        <td data-label="Parcela">{{ $p->sequencia }}@if ($p->observacao)<br><small class="muted">{{ $p->observacao }}</small>@endif</td>
                        <td data-label="Vencimento">{{ $p->vencimento->format('d/m/Y') }}</td>
                        <td data-label="Valor">{{ \App\Support\Dinheiro::formatar((int) $p->valor_centavos) }}</td>
                        <td data-label="Recebido">{{ \App\Support\Dinheiro::formatar((int) $p->pago_centavos) }}</td>
                        <td data-label="Meio previsto">{{ $p->rotuloMeio() ?? '—' }}</td>
                        <td data-label="Situação"><span @class(['tag', 'off' => $p->situacao === 'vencida', 'extra' => $p->situacao === 'parcial'])>{{ $p->rotuloSituacao() }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif

        @if ($podeCombinar && $recebivel && $fin['saldo'] > 0 && $combinadoSit)
            <details @if ($errors->has('parcelas') || $errors->has('motivo_combinado')) open @endif>
                <summary>{{ $combinadoSit ? 'Ajustar combinado de pagamento' : 'Combinar pagamento do saldo (parcelas com data)' }}</summary>
                <form method="POST" action="{{ route('combinados.registrar', $ordem) }}" class="combinado-form">
                    @csrf
                    <p class="hint">A soma das parcelas deve ser igual ao saldo a receber: <strong>{{ \App\Support\Dinheiro::formatar($fin['saldo']) }}</strong>. Linhas em branco são ignoradas.</p>
                    @php($linhas = max(3, count(old('parcelas', []))))
                    @for ($i = 0; $i < $linhas; $i++)
                        <fieldset class="parcela-linha">
                            <legend>Parcela {{ $i + 1 }}</legend>
                            <div class="field"><label for="parc-valor-{{ $i }}">Valor (R$)</label>
                                <input id="parc-valor-{{ $i }}" name="parcelas[{{ $i }}][valor]" inputmode="decimal" value="{{ old("parcelas.$i.valor", $i === 0 && ! $combinadoSit ? \App\Support\Dinheiro::paraCampo($fin['saldo']) : '') }}"></div>
                            <div class="field"><label for="parc-venc-{{ $i }}">Vencimento</label>
                                <input id="parc-venc-{{ $i }}" name="parcelas[{{ $i }}][vencimento]" type="date" min="{{ \App\Support\Fuso::exibir(now(), 'Y-m-d') }}" value="{{ old("parcelas.$i.vencimento") }}"></div>
                            <div class="field"><label for="parc-meio-{{ $i }}">Meio previsto</label>
                                <select id="parc-meio-{{ $i }}" name="parcelas[{{ $i }}][meio]">
                                    <option value="">Não definido</option>
                                    @foreach (\App\Models\ParcelaPrevista::MEIOS as $chave => $rotulo)
                                        <option value="{{ $chave }}" @selected(old("parcelas.$i.meio") === $chave)>{{ $rotulo }}</option>
                                    @endforeach
                                </select></div>
                            <div class="field"><label for="parc-obs-{{ $i }}">Observação</label>
                                <input id="parc-obs-{{ $i }}" name="parcelas[{{ $i }}][observacao]" maxlength="300" value="{{ old("parcelas.$i.observacao") }}"></div>
                        </fieldset>
                    @endfor
                    @if ($combinadoSit)
                        @include('partials.campo', ['nome' => 'motivo_combinado', 'rotulo' => 'Motivo do ajuste', 'id' => 'motivo-ajuste-combinado', 'obrigatorio' => true])
                    @endif
                    <button class="button" type="submit">{{ $combinadoSit ? 'Salvar ajuste' : 'Registrar combinado' }}</button>
                </form>
            </details>
        @endif
        @if ($podeCombinar && $combinadoSit)
            <details>
                <summary>Cancelar combinado</summary>
                <form method="POST" action="{{ route('combinados.cancelar', $ordem) }}" class="inline-form">
                    @csrf
                    @include('partials.campo', ['nome' => 'motivo_combinado', 'rotulo' => 'Motivo do cancelamento', 'id' => 'motivo-cancelar-combinado', 'obrigatorio' => true])
                    <button class="button" type="submit">Cancelar combinado</button>
                </form>
            </details>
        @endif
    </div>
@endif
