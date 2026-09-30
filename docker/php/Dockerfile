FROM php:8.4-cli-bookworm

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN install-php-extensions pdo_pgsql pgsql redis pcntl posix intl zip bcmath opcache \
 && apt-get update && apt-get install -y --no-install-recommends git unzip postgresql-client \
 && rm -rf /var/lib/apt/lists/*

COPY docker/php/engage.ini /usr/local/etc/php/conf.d/zz-engage.ini

WORKDIR /var/www/html
