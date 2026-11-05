@extends('layouts.app', ['titulo' => 'Integrações'])
@section('conteudo')
    <div class="page-head"><div><h1>Integrações</h1><p class="subtitle">@if ($simulado)Modo simulado: nenhum pagamento, mensagem ou documento fiscal real sai do sistema.@else Modo real configurado, mas nenhum provedor homologado existe ainda: as operações externas falham com aviso.@endif</p></div></div>
    @php($tag = $simulado ? ['extra', 'Simulado'] : ['off', 'Real indisponível'])
    <div class="cartoes-integracao">
        <article class="cartao-integracao">
            <header><h2>Pix</h2><span class="tag {{ $tag[0] }}">{{ $tag[1] }}</span></header>
            <ul>
                <li>Cobranças com validade de {{ $validadePix }} minutos, confirmadas só pelo retorno do provedor (webhook com assinatura e deduplicação por evento).</li>
                <li>Não existe botão para marcar Pix como pago.@if ($permiteSimularPix) Botão “simular pagamento” habilitado neste ambiente de teste.@endif</li>
                <li>Segredo do webhook: {{ $segredoPixDefinido ? 'definido no servidor' : 'não definido' }}.</li>
            </ul>
            <h3>Para produção</h3>
            <p class="hint">Escolher o banco ou PSP, implementar o adaptador real e cadastrar as credenciais nos segredos do servidor.</p>
        </article>
        <article class="cartao-integracao">
            <header><h2>Mensagens (WhatsApp/SMS)</h2><span class="tag {{ $tag[0] }}">{{ $tag[1] }}</span></header>
            <ul>
                <li>Fila persistente com até {{ $tentativasMensagem }} tentativas por mensagem; falhas aparecem em <a href="{{ route('pendencias.index', ['aba' => 'mensagens']) }}">Pendências e mensagens</a>.</li>
                <li>“Enviada” significa aceita pelo provedor, não lida pelo cliente.</li>
                @if ($simulado)<li>Mensagens são SIMULADAS e nunca enviadas a ninguém.</li>@endif
            </ul>
            <h3>Para produção</h3>
            <p class="hint">Contratar o provedor oficial (por exemplo, a API do WhatsApp Business), implementar o adaptador e cadastrar as credenciais.</p>
        </article>
        <article class="cartao-integracao">
            <header><h2>Fiscal (NFS-e / NF-e)</h2><span class="tag {{ $tag[0] }}">{{ $simulado ? 'Simulado — sem validade fiscal' : $tag[1] }}</span></header>
            <ul>
                <li>Emissão só quando solicitada na ordem; não é automática após o pagamento.</li>
                <li>Situações acompanhadas: pendente, processando, autorizado, rejeitado e cancelado.</li>
                @if ($simulado && $fiscalIndisponivel)<li>Simulação de indisponibilidade do emissor ligada.</li>@endif
            </ul>
            <h3>Para produção</h3>
            <p class="hint">Definir com o contador CNPJs, atividades, tratamento de peças e serviços e o momento da emissão; depois contratar o emissor.</p>
        </article>
    </div>
@endsection
