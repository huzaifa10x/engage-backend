# Production image for the Laravel backend (API, Super Admin, Horizon, scheduler, Reverb).
# FrankenPHP = PHP + Caddy in one binary: no separate php-fpm/nginx to configure.
# Multi-arch: builds natively on Oracle Ampere (arm64) and on x86.

# ── 1. Super Admin assets (Vite) ───────────────────────────────────────────────────────────
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build

# ── 2. PHP runtime ─────────────────────────────────────────────────────────────────────────
FROM dunglas/frankenphp:1-php8.4-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_pgsql pgsql redis pcntl posix intl zip bcmath opcache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php/engage.ini $PHP_INI_DIR/conf.d/zz-engage.ini
COPY deploy/opcache.ini $PHP_INI_DIR/conf.d/zz-opcache.ini
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

# Dependencies first so code-only changes reuse this layer.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && php artisan package:discover --ansi \
    && mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

ENV SERVER_NAME=":8000"
EXPOSE 8000

# Config/route/view caches need the runtime .env, so they are built when the container starts.
CMD ["sh", "-c", "php artisan optimize && exec frankenphp run --config /app/deploy/frankenphp.Caddyfile"]
