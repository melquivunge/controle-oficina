#!/bin/sh
# Restaura um backup no banco SEPARADO oficina_restauracao e compara com o banco principal. Não toca o banco principal.
# Uso: docker/scripts/restaurar-teste.sh backups/<data>
set -eu
cd "$(dirname "$0")/../.."
D="${1:?informe o diretório do backup}"
( cd "$D" && { command -v sha256sum >/dev/null && sha256sum -c SHA256SUMS || shasum -a 256 -c SHA256SUMS; } )
ALVO=oficina_restauracao
docker compose exec -T db psql -v ON_ERROR_STOP=1 -U oficina -d postgres -c "DROP DATABASE IF EXISTS $ALVO" -c "CREATE DATABASE $ALVO"
docker compose exec -T db pg_restore -U oficina -d "$ALVO" --no-owner --exit-on-error < "$D/oficina.dump"
CONSULTA="select string_agg(t || '=' || n, ' ' order by t) from (
  select 'users' t, count(*) n from users union all select 'unidades', count(*) from unidades union all
  select 'ordens', count(*) from ordens union all select 'orcamentos', count(*) from orcamentos union all
  select 'pagamentos', count(*) from pagamentos union all select 'anexos', count(*) from anexos union all
  select 'audit_events', count(*) from audit_events union all select 'soma_pagamentos', coalesce(sum(valor_centavos),0) from pagamentos) x"
ORIG=$(docker compose exec -T db psql -U oficina -d oficina -Atc "$CONSULTA")
REST=$(docker compose exec -T db psql -U oficina -d "$ALVO" -Atc "$CONSULTA")
echo "original:   $ORIG"
echo "restaurado: $REST"
TRIG=$(docker compose exec -T db psql -U oficina -d "$ALVO" -Atc "select count(*) from pg_trigger where tgname like 'audit_events_sem_%'")
echo "triggers de auditoria restaurados: $TRIG"
ARQ_DB=$(docker compose exec -T db psql -U oficina -d "$ALVO" -Atc "select count(*) from anexos")
ARQ_TAR=$(tar -tzf "$D/anexos.tgz" | grep -c '^./ordens/.*[^/]$' || true)
echo "anexos no banco restaurado: $ARQ_DB · arquivos de ordens no backup: $ARQ_TAR"
CHAVE_ATUAL=$(docker compose exec -T app cat /var/lib/oficina/chave/app.key | { command -v sha256sum >/dev/null && sha256sum || shasum -a 256; } | cut -c1-16)
CHAVE_BKP=$({ command -v sha256sum >/dev/null && sha256sum || shasum -a 256; } < "$D/app.key" | cut -c1-16)
echo "hash da chave: atual=$CHAVE_ATUAL backup=$CHAVE_BKP"
[ "$ORIG" = "$REST" ] && [ "$TRIG" = "2" ] && [ "$ARQ_DB" -le "$ARQ_TAR" ] && [ "$CHAVE_ATUAL" = "$CHAVE_BKP" ] && echo "RESTAURAÇÃO OK" || { echo "RESTAURAÇÃO DIVERGENTE" >&2; exit 1; }
