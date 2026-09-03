ARG PHP_VERSION=8.5.9

FROM php:${PHP_VERSION}-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git libicu-dev libpq-dev unzip \
    && docker-php-ext-install intl pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /consumer
