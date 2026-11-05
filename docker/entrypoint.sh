#!/bin/sh
# Carrega a chave estável do volume. Somente o serviço init cria a chave (OFICINA_CRIAR_CHAVE=1).
set -eu
ARQUIVO_CHAVE="${OFICINA_ARQUIVO_CHAVE:-/var/lib/oficina/chave/app.key}"

if [ -z "${APP_KEY:-}" ]; then
  if [ ! -s "$ARQUIVO_CHAVE" ] && [ "${OFICINA_CRIAR_CHAVE:-0}" = "1" ]; then
    umask 077
    php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;' > "$ARQUIVO_CHAVE"
    echo "init: chave da aplicação criada em $ARQUIVO_CHAVE (faça backup deste volume)." >&2
  fi
  if [ ! -s "$ARQUIVO_CHAVE" ]; then
    echo "ERRO: APP_KEY ausente e $ARQUIVO_CHAVE não existe. Execute 'docker compose up' para que o serviço init crie a chave." >&2
    exit 1
  fi
  APP_KEY="$(cat "$ARQUIVO_CHAVE")"
  export APP_KEY
fi

exec "$@"
