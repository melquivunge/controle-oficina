#!/bin/sh
# Backup do banco principal, anexos e chave da aplicação. Requer apenas Docker. Uso: docker/scripts/backup.sh
# Retenção sugerida: diários por 14 dias, mensais por 12 meses, cópia fora do servidor. A chave (app.key) é sigilosa.
set -eu
cd "$(dirname "$0")/../.."
TS=$(date -u +%Y%m%dT%H%M%SZ)
D="backups/$TS"
mkdir -p "$D"
umask 077
docker compose exec -T db pg_dump -U oficina -Fc oficina > "$D/oficina.dump"
docker compose exec -T app tar -C /app/storage/app/private --exclude=./demo --exclude=./livewire-tmp -czf - . > "$D/anexos.tgz"
docker compose exec -T app cat /var/lib/oficina/chave/app.key > "$D/app.key"
( cd "$D" && { command -v sha256sum >/dev/null && sha256sum oficina.dump anexos.tgz app.key || shasum -a 256 oficina.dump anexos.tgz app.key; } > SHA256SUMS )
for f in oficina.dump anexos.tgz app.key; do [ -s "$D/$f" ] || { echo "ERRO: $D/$f vazio" >&2; exit 1; }; done
echo "Backup concluído em $D"
