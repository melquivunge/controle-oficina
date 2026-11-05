# Controle de Oficina

Aplicação Laravel 13 + Livewire 4 (PHP 8.4, FrankenPHP), PostgreSQL 17 e Redis 7.4. Tudo roda em Docker; o host precisa apenas de Docker Desktop/Engine com Compose v2. Não instale PHP, Composer ou Node no host.

## Iniciar
```sh
cp .env.example .env    # defina DB_PASSWORD, DEMO_DB_PASSWORD e REDIS_PASSWORD (senhas locais fortes)
docker compose up       # ou: docker compose up -d
```
Acesse http://localhost:8092 (porta em `APP_PORT`). Saúde: http://localhost:8092/saude (503 se banco ou Redis falharem).

Serviços: `db`, `redis`, `init` (cria a chave estável no volume `chave` e aplica migrações uma única vez), `app`, `worker`, `scheduler`. Workers não migram. Sem `.env` ou senhas, o Compose falha com mensagem explícita.

Após alterar código ou dependências: `docker compose build && docker compose up -d`.
(Observado no Compose 2.38: `up -d --build` às vezes reconstrói a imagem sem recriar os contêineres; o `up -d` em seguida recria. Causa exata não isolada.)

## Testes
```sh
docker compose --profile test run --rm test
```
Usa o banco isolado `oficina_teste` (forçado no phpunit.xml).

## Branches e CI
`main` corresponde ao ambiente `production` e `staging` ao ambiente `staging`. Cada push nessas branches, e cada PR, roda a suíte em Docker no GitHub Actions (`.github/workflows/testes.yml`). Se os testes passarem, o mesmo workflow publica a imagem no GHCR e atualiza o servidor.

## Servidor (deploy)
- **Estrutura:** uma VPS Ubuntu com `/opt/oficina/{edge,production,staging}`. O Caddy de `deploy/edge` é o único com portas públicas (80/443), com HTTPS automático; `oficina.solutions-ss.com` vai para a produção e `staging.solutions-ss.com` para o staging.
- **Preparação única (root):** `deploy/preparar-servidor.sh "<chave pública do deploy>"` instala Docker, firewall, fail2ban, o usuário `deploy` e o backup diário da produção (14 dias, sem cópia externa ainda).
- **Segredos do repositório:** `DEPLOY_HOST`, `DEPLOY_SSH_KEY` e `DEPLOY_KNOWN_HOSTS`. As senhas dos ambientes são geradas no servidor (`deploy/gerar-env.sh`) e nunca passam pelo GitHub.
- **Atualização:** `deploy/deploy.sh <ambiente> <imagem>`. Se os serviços não ficarem saudáveis, volta para a imagem anterior; a reversão não desfaz migrações.
- **Integrações:** produção usa `OFICINA_INTEGRACOES=real` (sem provedor homologado, Pix, mensagens e nota fiscal aparecem como indisponíveis); staging usa simuladores e nunca deve receber dados reais.
- **Comandos dentro do servidor:** em `/opt/oficina/<ambiente>`, o `.env` define `COMPOSE_FILE`, então `docker compose ps` e `docker/scripts/backup.sh` funcionam sem opções extras.

## Dados persistentes
Volumes: `banco` (PostgreSQL), `anexos` (arquivos privados), `chave` (APP_KEY), `redis`. `docker compose down` preserva dados. **Não use `down -v`** como rotina: apaga banco, anexos e a chave. Faça backup do volume `chave` junto com o banco.

## Demonstração
Nunca carregada no `up`. Banco separado `oficina_demo` com credencial própria; comandos bloqueados em produção.
```sh
docker compose exec app php artisan demo:load    # explícito e idempotente
docker compose exec app php artisan demo:reset   # pede confirmação RESETAR-DEMO; só atua no banco demo com marcador
OFICINA_BANCO=demo docker compose up -d          # navegar na demo (banner DEMONSTRAÇÃO)
```
Roteiro e contas: [docs/demo-roteiro.md](docs/demo-roteiro.md).

## Primeiro acesso (banco principal)
```sh
printf 'SUA-SENHA\n' | docker compose exec -T app php artisan oficina:criar-proprietario --nome="Nome" --email=voce@exemplo.com
```
O comando mostra a chave da verificação em duas etapas. Depois, em Configurações: unidades, contas oficiais de recebimento e usuários.

## Backup e restauração
```sh
docker/scripts/backup.sh                                  # banco, anexos e chave em backups/<data> (com SHA256SUMS)
docker/scripts/restaurar-teste.sh backups/<data>          # restaura em oficina_restauracao e compara com o principal
```
Guarde a chave (`app.key`) com o mesmo cuidado de uma senha. Retenção sugerida no script.

## Verificações
- Testes: `docker compose --profile test run --rm test`
- E2E (Playwright em contêiner): ver comandos em `docs/evidencias/` e `prototipo/README.md`.
- Recuperação de filas/integrações: `sh docs/evidencias/scripts/recuperacao.sh`
- Rastreabilidade de requisitos e pendências: [docs/rastreabilidade.md](docs/rastreabilidade.md)

## Integrações
`OFICINA_INTEGRACOES=simulado`: nenhum pagamento, mensagem ou documento fiscal real. Integrações reais dependem de escolha e homologação de provedores.
