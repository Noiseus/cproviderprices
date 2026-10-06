FROM composer:2.8.12 AS vendor

WORKDIR /app

COPY apps/api ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --no-progress \
    --optimize-autoloader \
    --classmap-authoritative

FROM php:8.4.12-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl libpq-dev libzip-dev \
    && docker-php-ext-install pdo_pgsql pcntl zip \
    && pecl install redis-6.2.0 \
    && docker-php-ext-enable redis \
    && a2enmod rewrite headers \
    && sed -ri 's!^Listen 80$!Listen 8080!' /etc/apache2/ports.conf \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY infra/docker/php-imports.ini /usr/local/etc/php/conf.d/imports.ini
COPY infra/docker/apache-production.conf /etc/apache2/sites-available/000-default.conf
COPY --from=vendor --chown=www-data:www-data /app ./

RUN chown -R www-data:www-data bootstrap/cache storage

EXPOSE 8080

HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=5 \
  CMD curl --fail --silent --show-error http://localhost:8080/api/v1/health || exit 1

CMD ["sh", "-c", "php artisan app:validate-environment && exec apache2-foreground"]
