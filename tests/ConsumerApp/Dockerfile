FROM php:8.5.9-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libpq-dev \
    && docker-php-ext-install intl pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /consumer
