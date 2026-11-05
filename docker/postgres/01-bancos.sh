#!/bin/sh
# Executado só na primeira inicialização do volume do PostgreSQL.
# Cria banco de demonstração (credencial própria) e banco de testes, separados do principal.
set -eu
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres <<SQL
CREATE ROLE oficina_demo LOGIN PASSWORD '${DEMO_DB_PASSWORD}';
CREATE DATABASE oficina_demo OWNER oficina_demo;
REVOKE ALL ON DATABASE oficina_demo FROM PUBLIC;
REVOKE ALL ON DATABASE ${POSTGRES_DB} FROM PUBLIC;
CREATE DATABASE oficina_teste OWNER ${POSTGRES_USER};
SQL
