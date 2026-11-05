#!/bin/sh
# Cria o .env do ambiente uma única vez. As senhas nascem no servidor e não passam pelo GitHub. Uso: gerar-env.sh production|staging
set -eu
AMB="$1"
cd "$(dirname "$0")/.."
[ ! -e .env ] || { echo "ERRO: .env já existe; nada foi alterado." >&2; exit 1; }
case "$AMB" in
  # Produção: integração sem provedor homologado falha de forma visível; nunca finge que enviou ou cobrou.
  production) DOMINIO=oficina.solutions-ss.com; APP_ENV=production; INTEGRACOES=real; SIMULAR=false ;;
  # Staging: simuladores; nunca colocar dados reais neste ambiente.
  staging) DOMINIO=staging.solutions-ss.com; APP_ENV=staging; INTEGRACOES=simulado; SIMULAR=true ;;
  *) echo "ERRO: ambiente inválido: $AMB" >&2; exit 1 ;;
esac
s() { openssl rand -hex 32; }
umask 077
cat > .env <<ENV
COMPOSE_PROJECT_NAME=oficina-$AMB
COMPOSE_FILE=compose.yaml:deploy/compose.server.yaml
OFICINA_AMBIENTE=$AMB
OFICINA_IMAGEM=
APP_ENV=$APP_ENV
APP_DEBUG=false
APP_URL=https://$DOMINIO
LOG_LEVEL=info
SESSION_SECURE_COOKIE=true
OFICINA_PROXIES_CONFIAVEIS=10.250.250.0/24
OFICINA_BANCO=principal
OFICINA_INTEGRACOES=$INTEGRACOES
OFICINA_PERMITE_SIMULAR_PAGAMENTO=$SIMULAR
DB_PASSWORD=$(s)
DEMO_DB_PASSWORD=$(s)
APP_DB_PASSWORD=$(s)
OFICINA_PIX_WEBHOOK_SEGREDO=$(s)
REDIS_PASSWORD=$(s)
ENV
echo "$AMB: .env criado."
