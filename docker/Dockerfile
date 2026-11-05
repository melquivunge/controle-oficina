# syntax=docker/dockerfile:1
# Imagem única para app, worker, scheduler, init e testes. Contexto: raiz do projeto.

FROM dunglas/frankenphp:1.9-php8.4-bookworm AS base
RUN install-php-extensions pdo_pgsql redis intl zip bcmath pcntl \
 && apt-get update && apt-get install -y --no-install-recommends postgresql-client unzip procps \
 && rm -rf /var/lib/apt/lists/* \
 && useradd --uid 1000 --create-home oficina \
 && setcap -r /usr/local/bin/frankenphp \
 && chown -R oficina:oficina /config/caddy /data/caddy
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
ENV SERVER_NAME=":8080" COMPOSER_HOME=/tmp/composer
WORKDIR /app

FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY app/package.json app/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY app/vite.config.js ./
COPY app/resources ./resources
RUN npm run build

FROM base AS source
COPY --chown=oficina:oficina app/ ./
COPY --from=assets --chown=oficina:oficina /app/public/build ./public/build
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/oficina-entrypoint
RUN mkdir -p /var/lib/oficina/chave && chown oficina:oficina /app && chown -R oficina:oficina /var/lib/oficina /app/storage /app/bootstrap/cache
ENTRYPOINT ["oficina-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# Testes: inclui dependências de desenvolvimento.
FROM source AS test
USER oficina
# ponytail: .env vazio evita aviso de leitura do arquivo ausente no printer de testes (origem exata não localizada).
RUN composer install --no-interaction --no-progress --prefer-dist --optimize-autoloader && touch .env

FROM source AS app
USER oficina
RUN composer install --no-interaction --no-progress --prefer-dist --no-dev --optimize-autoloader
