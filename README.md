# Controle de Oficina

Sistema de gestão para uma rede de oficinas mecânicas. Ele registra cada veículo da entrada à entrega, com ordem de serviço numerada, orçamento versionado, aprovação do cliente por link, execução, caixa, conferência diária, painéis por unidade e auditoria. Cada pessoa usa conta própria, com permissões de proprietário, supervisor, gerente, recepção/caixa ou mecânico, e o acesso entre unidades é verificado no servidor.

- **Produção:** https://oficina.controleoficina.com.br
- **Staging (testes, só simuladores):** https://staging.controleoficina.com.br
- **Manual de uso, com capturas de todas as telas:** [docs/manual/MANUAL.md](docs/manual/MANUAL.md)

## Situação atual
- **Pronto e testado:** ordens, orçamentos, aprovação, execução, caixa, conferência, painéis (incluindo a versão simples para dono e gerente), auditoria, permissões e o deploy automático.
- **Ainda não é real:** Pix, mensagens/WhatsApp e nota fiscal existem só como simuladores. Em produção aparecem como **indisponíveis**, até a escolha e a homologação dos provedores (bancos, contador). Dinheiro e cartão são registros manuais, conferidos no fechamento do caixa.

## Tecnologia
Laravel 13 + Livewire 4 (PHP 8.4, FrankenPHP), PostgreSQL 17 e Redis 7.4. **Tudo roda em Docker**: o computador precisa só de Docker Desktop/Engine com Compose v2. Não instale PHP, Composer ou Node na máquina.

## Rodar no computador
```sh
cp .env.example .env    # defina DB_PASSWORD, DEMO_DB_PASSWORD, APP_DB_PASSWORD, OFICINA_PIX_WEBHOOK_SEGREDO e REDIS_PASSWORD
docker compose up       # ou: docker compose up -d
```
Acesse http://localhost:8092 (porta em `APP_PORT`). A página de saúde fica em http://localhost:8092/saude e responde 503 se o banco ou o Redis falharem.

**Serviços:**
- `db` e `redis`: banco e fila.
- `init`: cria a chave estável no volume `chave` e aplica as migrações uma única vez.
- `app`, `worker` e `scheduler`: aplicação, fila e tarefas agendadas. Os workers não migram.

Sem `.env` ou sem as senhas, o Compose falha com mensagem explícita.

Depois de alterar código ou dependências: `docker compose build && docker compose up -d`. No Compose 2.38, `up -d --build` às vezes reconstrói a imagem sem recriar os contêineres; o `up -d` seguinte recria. A causa exata não foi isolada.

## Testes
```sh
docker compose --profile test run --rm test
```
Usa o banco isolado `oficina_teste`, forçado no `phpunit.xml`.

## Branches, CI e deploy
- **Branches:** `main` vai para a produção e `staging` para o staging.
- **Pipeline:** cada push nessas branches, e cada PR, roda a suíte em Docker no GitHub Actions (`.github/workflows/testes.yml`). Se os testes passarem, o código vai ao servidor por SSH, a imagem é construída lá e o ambiente é atualizado.
- **Deploy manual:** `gh workflow run testes.yml --ref staging`, ou `--ref main` para a produção.

## Servidor
- **Estrutura:** uma VPS Ubuntu com `/opt/oficina/{edge,production,staging}`. O Caddy de `deploy/edge` é o único serviço com portas públicas (80/443) e emite o HTTPS automaticamente.
- **Preparação única (como root):** `deploy/preparar-servidor.sh "<chave pública do deploy>"`. Instala Docker, firewall, fail2ban, o usuário `deploy` e o backup diário da produção, guardado por 14 dias.
- **Segredos do repositório:** `DEPLOY_HOST`, `DEPLOY_SSH_KEY` e `DEPLOY_KNOWN_HOSTS`. As senhas de cada ambiente são geradas no próprio servidor (`deploy/gerar-env.sh`) e nunca passam pelo GitHub.
- **Atualização:** `deploy/deploy.sh <ambiente> <imagem>`. Se os serviços não ficarem saudáveis, volta sozinho para a imagem anterior. A reversão não desfaz migrações.
- **Integrações:** a produção usa `OFICINA_INTEGRACOES=real`, e o que não tem provedor homologado aparece como indisponível. O staging usa simuladores e nunca deve receber dados reais.
- **Comandos no servidor:** em `/opt/oficina/<ambiente>`, o `.env` define `COMPOSE_FILE`, então `docker compose ps` e `docker/scripts/backup.sh` funcionam sem opções extras.

## Primeiro acesso
**No servidor (produção).** A senha é pedida na tela e não aparece:
```sh
ssh -t deploy@<IP> 'cd /opt/oficina/production && docker compose exec app php artisan oficina:criar-proprietario'
```

**No computador:**
```sh
docker compose exec app php artisan oficina:criar-proprietario
```

O comando mostra a chave da verificação em duas etapas. Depois, em Configurações, cadastre unidades, contas oficiais de recebimento e usuários.

## Demonstração
A demo nunca é carregada no `up`. Ela usa o banco separado `oficina_demo`, com credencial própria, e os comandos são bloqueados em produção.
```sh
docker compose exec app php artisan demo:load    # explícito; repetir não duplica
docker compose exec app php artisan demo:reset   # pede a confirmação RESETAR-DEMO; só atua no banco demo
OFICINA_BANCO=demo docker compose up -d          # navegar na demo (aparece a faixa DEMONSTRAÇÃO)
```
Roteiro e contas: [docs/demo-roteiro.md](docs/demo-roteiro.md). A senha da demo é pública. Nunca deixe a demo acessível por um endereço público.

## Dados persistentes
**Volumes:**
- `banco`: PostgreSQL;
- `anexos`: arquivos privados;
- `chave`: APP_KEY;
- `redis`.

`docker compose down` preserva os dados. **Não use `down -v`** como rotina: apaga o banco, os anexos e a chave.

## Backup e restauração
```sh
docker/scripts/backup.sh                          # banco, anexos e chave em backups/<data>, com SHA256SUMS
docker/scripts/restaurar-teste.sh backups/<data>  # restaura em oficina_restauracao e compara com o principal
```
Guarde a chave (`app.key`) com o mesmo cuidado de uma senha.

**Pendência:** o backup do servidor ainda não tem cópia fora dele.
