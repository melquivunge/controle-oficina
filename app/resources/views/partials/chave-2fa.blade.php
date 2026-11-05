@if ($chave = session('chave_2fa'))
    <section class="alert sucesso" role="status">
        <strong>Chave de verificação em duas etapas de {{ $chave['email'] }}</strong>
        <p>Mostrada somente agora. Entregue pessoalmente; a pessoa deve adicioná-la ao aplicativo autenticador antes do primeiro acesso.</p>
        <p class="code-box">{{ trim(chunk_split($chave['segredo'], 4, ' ')) }}</p>
        <details class="json"><summary>Endereço completo (para aplicativos que aceitam colar)</summary><p class="code-box">{{ $chave['uri'] }}</p></details>
    </section>
@endif
