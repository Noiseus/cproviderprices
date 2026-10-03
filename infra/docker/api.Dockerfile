FROM composer:2.8.12 AS vendor

WORKDIR /app
COPY apps/api ./
RUN composer install --no-interaction --prefer-dist --no-progress

FROM php:8.4.12-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl libpq-dev libzip-dev \
    && docker-php-ext-install pdo_pgsql pcntl zip \
    && pecl install redis-6.2.0 \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY infra/docker/php-imports.ini /usr/local/etc/php/conf.d/imports.ini
COPY --from=vendor /app ./

EXPOSE 8080

CMD ["sh", "-c", "php artisan app:validate-environment && php artisan serve --host=0.0.0.0 --port=8080"]
