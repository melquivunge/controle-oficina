#!/bin/sh
# Atualiza um ambiente para a imagem indicada; se os serviços não ficarem saudáveis, volta para a imagem anterior.
# Uso (na pasta /opt/oficina/<ambiente>): deploy/deploy.sh production|staging <imagem>
# ponytail: a reversão troca só a imagem; migração destrutiva exige plano próprio (padroes-tecnicos.md).
set -eu
AMB="$1"; IMAGEM="$2"
case "$AMB" in production|staging) ;; *) echo "ERRO: ambiente inválido: $AMB" >&2; exit 1 ;; esac
cd "$(dirname "$0")/.."
chmod +x deploy/*.sh docker/scripts/*.sh
[ -e .env ] || deploy/gerar-env.sh "$AMB"

# Borda: sobe na primeira vez e recarrega quando a configuração mudar, sem interromper as conexões.
EDGE="$(dirname "$PWD")/edge"
MUDOU=0
if ! diff -qr -x acesso-staging.caddy deploy/edge "$EDGE" >/dev/null 2>&1; then MUDOU=1; mkdir -p "$EDGE"; rm -rf "$EDGE/manual"; cp -R deploy/edge/. "$EDGE/"; fi
# Staging fechado até a senha ser definida (deploy/staging-senha.sh): a demo tem senha pública.
if [ ! -e "$EDGE/acesso-staging.caddy" ]; then MUDOU=1; printf 'respond "Staging fechado: senha de acesso ainda não definida." 403\n' > "$EDGE/acesso-staging.caddy"; fi
(
  cd "$EDGE"
  docker compose up -d --wait
  if [ "$MUDOU" = 1 ]; then docker compose exec -T caddy caddy reload --config /etc/caddy/Caddyfile; fi
)

definir_imagem() { sed "s|^OFICINA_IMAGEM=.*|OFICINA_IMAGEM=$1|" .env > .env.novo; cat .env.novo > .env; rm -f .env.novo; }
ANTERIOR="$(sed -n 's/^OFICINA_IMAGEM=//p' .env)"
definir_imagem "$IMAGEM"
if ! docker compose pull --quiet --policy missing app; then
  definir_imagem "$ANTERIOR"
  echo "ERRO: imagem indisponível; o ambiente não foi alterado." >&2
  exit 1
fi
if docker compose up -d --wait --remove-orphans; then
  docker image prune -af --filter "until=168h" >/dev/null; docker builder prune -f --filter "until=168h" >/dev/null
  echo "$AMB: atualizado para $IMAGEM"
  exit 0
fi
echo "ERRO: serviços não ficaram saudáveis." >&2
docker compose ps -a >&2 || true
definir_imagem "$ANTERIOR"
if [ -n "$ANTERIOR" ]; then
  docker compose up -d --wait --remove-orphans && echo "$AMB: revertido para $ANTERIOR" >&2
fi
exit 1
