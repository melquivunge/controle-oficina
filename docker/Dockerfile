# syntax=docker/dockerfile:1
# Imagem única para app, worker, scheduler, init e testes. Contexto: raiz do projeto.

FROM dunglas/frankenphp:1.9-php8.4-bookworm AS base
RUN install-php-extensions pdo_pgsql redis intl zip bcmath pcntl soap gd \
 && apt-get update && apt-get install -y --no-install-recommends postgresql-client unzip procps \
 && rm -rf /var/lib/apt/lists/* \
 && useradd --uid 1000 --create-home oficina \
 && setcap -r /usr/local/bin/frankenphp \
 && chown -R oficina:oficina /config/caddy /data/caddy
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
# Certificados A1 antigos (.pfx com RC2/3DES) só abrem no OpenSSL 3 com o provedor "legacy" (verificado 09/11/2026).
RUN printf 'openssl_conf = oficina\n[oficina]\nproviders = provs\n[provs]\ndefault = def\nlegacy = leg\n[def]\nactivate = 1\n[leg]\nactivate = 1\n' > /etc/ssl/openssl-oficina.cnf
# Raiz ICP-Brasil v10 (SVRS/NF-e): SHA-256 6E:0B:FF:06:...:89:F6, conferida entre ITI e o pedido no Mozilla (bug 1674669).
COPY docker/certs/icp-brasil-raiz-v10.pem /tmp/icp.pem
RUN cat /etc/ssl/certs/ca-certificates.crt /tmp/icp.pem > /etc/ssl/oficina-ca.pem && rm /tmp/icp.pem \
 && echo 'zend.exception_ignore_args=On' > /usr/local/etc/php/conf.d/oficina-seguranca.ini
ENV SERVER_NAME=":8080" COMPOSER_HOME=/tmp/composer OPENSSL_CONF=/etc/ssl/openssl-oficina.cnf
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
