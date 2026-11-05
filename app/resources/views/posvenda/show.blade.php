@extends('layouts.app', ['titulo' => 'Pós-venda · '.$ordem->numero])
@section('conteudo')
    <a class="back" href="{{ route('posvenda.index') }}">← Pós-venda</a>
    <div class="page-head">
        <div><h1>Pós-venda · {{ $ordem->numero }}</h1>
            <p class="subtitle">{{ $ordem->cliente?->nome }} · {{ $ordem->veiculo?->modelo }} {{ $ordem->veiculo?->placaFormatada() }} · entregue em {{ \App\Support\Fuso::exibir($ordem->entrega?->entregue_em, 'd/m/Y') }} · {{ $ordem->unidade?->nome }}</p></div>
        <a class="button" href="{{ route('ordens.show', $ordem) }}">Abrir OS</a>
    </div>
    <div class="detail-grid">
        <div>
            <section class="detail-block">
                <h2>Atendimento</h2>
                <dl class="facts">
                    <div><dt>Cliente</dt><dd>{{ $ordem->cliente?->nome }}</dd></div>
                    @if ($verContato)
                        <div><dt>Telefone</dt><dd>@if ($ordem->cliente?->telefone){{ \App\Support\Normaliza::telefoneExibicao($ordem->cliente->telefone) }}
                            · <a class="row-link" href="https://wa.me/55{{ $ordem->cliente->telefone }}" target="_blank" rel="noopener noreferrer">WhatsApp</a>
                            · <a class="row-link" href="tel:+55{{ $ordem->cliente->telefone }}">Ligar</a>@else — @endif</dd></div>
                    @endif
                    <div><dt>Serviço</dt><dd>{{ \App\Services\AvisosColaborador::resumoServico($ordem, 300) }}</dd></div>
                    @if ($verValores)<div><dt>Valor</dt><dd>{{ \App\Support\Dinheiro::formatar((int) ($ordem->orcamentoAprovado?->total_centavos ?? 0)) }}</dd></div>@endif
                </dl>
                <p class="hint">O link do WhatsApp só abre a conversa no seu aparelho; o sistema não envia nada.</p>
            </section>

            <form method="POST" action="{{ route('posvenda.registrar', $ordem) }}" class="form-panel" id="registrar">
                @csrf
                <section class="form-section">
                    <h2>Registrar contato</h2>
                    <p class="section-copy">Cada tentativa fica no histórico. Um novo contato encerra o retorno agendado anteriormente.</p>
                    <div class="fields">
                        @include('partials.campo', ['nome' => 'tentativa', 'rotulo' => 'Tentativa', 'tipo' => 'select', 'opcoes' => ['' => 'Escolha'] + \App\Models\PosVendaContato::TENTATIVAS, 'obrigatorio' => true])
                        @include('partials.campo', ['nome' => 'resultado', 'rotulo' => 'Resultado', 'tipo' => 'select', 'opcoes' => ['' => 'Escolha'] + \App\Models\PosVendaContato::RESULTADOS, 'obrigatorio' => true])
                    </div>
                    <fieldset class="pv-nps" @error('nota') aria-describedby="nota-erro" @enderror>
                        <legend>Nota do atendimento, de 0 a 10 <span>(só se o cliente respondeu)</span></legend>
                        <div class="pv-nps-escala">
                            <label class="pv-nps-opcao pv-nps-nenhuma"><input type="radio" name="nota" value="" @checked(old('nota', '') === '')><span>Sem nota</span></label>
                            @for ($n = 0; $n <= 10; $n++)
                                <label class="pv-nps-opcao {{ $n >= 9 ? 'promotor' : ($n >= 7 ? 'neutro' : 'detrator') }}"><input type="radio" name="nota" value="{{ $n }}" @checked(old('nota') === (string) $n)><span>{{ $n }}</span></label>
                            @endfor
                        </div>
                        @error('nota')<span class="field-error" id="nota-erro">{{ $message }}</span>@enderror
                    </fieldset>
                    <div class="field full"><label for="comentario">Comentário <span>(opcional)</span></label>
                        <textarea id="comentario" name="comentario" maxlength="2000" @error('comentario') aria-invalid="true" aria-describedby="comentario-erro" @enderror>{{ old('comentario') }}</textarea>
                        @error('comentario')<span class="field-error" id="comentario-erro">{{ $message }}</span>@enderror</div>
                    <div class="pv-flags">
                        <div class="field"><label class="check-linha"><input type="checkbox" name="problema_servico" value="1" @checked(old('problema_servico'))> Problema no serviço</label>
                            <label for="problema_descricao" class="sr-only">Descrição do problema</label>
                            <textarea id="problema_descricao" name="problema_descricao" maxlength="1000" placeholder="O que o cliente relatou" @error('problema_descricao') aria-invalid="true" aria-describedby="problema-erro" @enderror>{{ old('problema_descricao') }}</textarea>
                            @error('problema_descricao')<span class="field-error" id="problema-erro">{{ $message }}</span>@enderror</div>
                        <div class="field"><label class="check-linha"><input type="checkbox" name="negociacao_fora_padrao" value="1" @checked(old('negociacao_fora_padrao'))> Negociação fora do padrão</label>
                            <label for="negociacao_descricao" class="sr-only">Descrição da negociação</label>
                            <textarea id="negociacao_descricao" name="negociacao_descricao" maxlength="1000" placeholder="Desconto, prazo ou condição combinada fora do padrão" @error('negociacao_descricao') aria-invalid="true" aria-describedby="negociacao-erro" @enderror>{{ old('negociacao_descricao') }}</textarea>
                            @error('negociacao_descricao')<span class="field-error" id="negociacao-erro">{{ $message }}</span>@enderror</div>
                    </div>
                    <div class="fields">
                        @include('partials.campo', ['nome' => 'retorno_em', 'rotulo' => 'Agendar retorno (opcional)', 'tipo' => 'date', 'atributos' => ['min' => $hoje]])
                    </div>
                </section>
                <div class="form-footer"><span class="hint">Problema ou negociação fora do padrão viram pendência para o gerente resolver.</span><button class="button primary" type="submit">Registrar contato</button></div>
            </form>
        </div>
        <aside>
            <section class="detail-block">
                <h2>Histórico de contatos</h2>
                @if ($contatos->isEmpty())
                    <p class="hint">Nenhum contato ainda. Registre a primeira tentativa ao lado.</p>
                @else
                    <ul class="timeline">
                        @foreach ($contatos as $c)
                            <li>
                                <strong>{{ $c->rotuloTentativa() }} · {{ $c->rotuloResultado() }}</strong>
                                @if ($c->nota !== null) <span class="pv-nota {{ $c->nota >= 9 ? 'promotor' : ($c->nota >= 7 ? 'neutro' : 'detrator') }}">{{ $c->nota }}</span>@endif
                                <small>{{ \App\Support\Fuso::exibir($c->registrado_em) }} · {{ $c->autor?->name }}</small>
                                @if ($c->comentario)<small>“{{ $c->comentario }}”</small>@endif
                                @if ($c->problema_servico)<small><span class="tag {{ in_array($c->id, $abertos, true) ? 'extra' : '' }}">Problema</span> {{ $c->problema_descricao }}</small>@endif
                                @if ($c->negociacao_fora_padrao)<small><span class="tag {{ in_array($c->id, $abertos, true) ? 'extra' : '' }}">Negociação</span> {{ $c->negociacao_descricao }}</small>@endif
                                @if ($c->retorno_em)<small>Retorno agendado para {{ $c->retorno_em->format('d/m/Y') }}@if (! $loop->first) (encerrado por contato posterior)@endif</small>@endif
                            </li>
                        @endforeach
                    </ul>
                    @if ($abertos && auth()->user()->can('resolver_divergencias'))<p><a class="row-link" href="{{ route('pendencias.index') }}">Resolver em Pendências →</a></p>@endif
                @endif
            </section>
        </aside>
    </div>
@endsection
