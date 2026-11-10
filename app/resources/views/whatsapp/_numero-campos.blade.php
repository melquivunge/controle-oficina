{{-- Campos do número oficial. $n: WhatsappNumero|null; $p: prefixo de id. Valores antigos só para o formulário que foi enviado. --}}
@php($deste = old('_numero') === ($n ? (string) $n->id : 'novo'))
@php($v = fn ($campo, $padrao = null) => $deste ? old($campo, $padrao) : $padrao)
<div class="fields">
    <div class="field"><label for="{{ $p }}nome">Descrição</label><input id="{{ $p }}nome" name="nome" maxlength="80" required value="{{ $v('nome', $n?->nome) }}" placeholder="Ex.: WhatsApp Central"></div>
    <div class="field"><label for="{{ $p }}telefone">Telefone (DDD + número)</label><input id="{{ $p }}telefone" name="telefone" type="tel" inputmode="tel" required value="{{ $v('telefone', $n?->telefone) }}"></div>
    <div class="field"><label for="{{ $p }}unidade">Pertence a</label>
        <select id="{{ $p }}unidade" name="unidade_id">@foreach ($opcoesUnidade as $k => $t)<option value="{{ $k }}" @selected((string) $v('unidade_id', $n?->unidade_id) === (string) $k)>{{ $t }}</option>@endforeach</select></div>
    <div class="field"><label for="{{ $p }}minutos">Alerta sem resposta após (minutos)</label><input id="{{ $p }}minutos" name="minutos_alerta" type="number" min="1" max="1440" required value="{{ $v('minutos_alerta', $n?->minutos_alerta ?? 15) }}"></div>
    <div class="field"><label for="{{ $p }}provedor">Integração</label>
        <select id="{{ $p }}provedor" name="provedor">@foreach ($provedores as $k => $t)<option value="{{ $k }}" @selected($v('provedor', $n?->provedor ?? 'simulado') === $k)>{{ $t }}</option>@endforeach</select></div>
    <div class="field"><label for="{{ $p }}modo">Modo de conexão</label>
        <select id="{{ $p }}modo" name="modo_conexao" aria-describedby="{{ $p }}modo-ajuda">@foreach ($modos as $k => $t)<option value="{{ $k }}" @selected($v('modo_conexao', $n?->modo_conexao ?? 'api') === $k)>{{ $t }}</option>@endforeach</select>
        <p class="hint" id="{{ $p }}modo-ajuda">Informativo. Em coexistência o app WhatsApp Business do celular continua ativo e as respostas dadas nele também aparecem na Central.</p></div>
    <div class="field"><label for="{{ $p }}pnid">Phone number ID <span>(somente API oficial)</span></label><input id="{{ $p }}pnid" name="phone_number_id" inputmode="numeric" maxlength="64" value="{{ $v('phone_number_id', $n?->phone_number_id) }}"></div>
    <div class="field"><label for="{{ $p }}token">Token de acesso <span>{{ $n?->credencial('token') ? '(salvo — em branco mantém)' : '(API oficial ou token do aparelho no Drope)' }}</span></label><input id="{{ $p }}token" name="token" type="password" autocomplete="new-password" maxlength="1000"></div>
    <div class="field"><label for="{{ $p }}secret">App secret <span>{{ $n?->credencial('app_secret') ? '(salvo — em branco mantém)' : '(assinatura do webhook)' }}</span></label><input id="{{ $p }}secret" name="app_secret" type="password" autocomplete="new-password" maxlength="200"></div>
    <div class="field"><label for="{{ $p }}signing">Segredo do webhook <span>{{ $n?->credencial('signing_secret') ? '(salvo — em branco mantém)' : '(Drope: preenchido ao registrar o webhook)' }}</span></label><input id="{{ $p }}signing" name="signing_secret" type="password" autocomplete="new-password" maxlength="200"></div>
    <div class="field"><label for="{{ $p }}verify">Token de verificação do webhook <span>{{ $n?->credencial('verify_token') ? '(salvo — em branco mantém)' : '(mín. 12 caracteres)' }}</span></label><input id="{{ $p }}verify" name="verify_token" type="password" autocomplete="new-password" maxlength="200"></div>
    <div class="field full"><label for="{{ $p }}modelos">Modelos aprovados <span>(um por linha: nome|idioma|descrição)</span></label>
        <textarea id="{{ $p }}modelos" name="modelos" rows="3" maxlength="3000" placeholder="retomar_contato|pt_BR|Retomar contato após 24 h">{{ $v('modelos', collect($n?->modelos ?? [])->map(fn ($m) => $m['nome'].'|'.$m['idioma'].($m['descricao'] ? '|'.$m['descricao'] : ''))->implode("\n")) }}</textarea></div>
</div>
