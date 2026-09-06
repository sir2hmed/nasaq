FROM php:8.5-cli-alpine

RUN apk add --no-cache icu-libs libpq libzip oniguruma \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS icu-dev libzip-dev oniguruma-dev postgresql-dev \
    && docker-php-ext-install intl mbstring pcntl pdo_pgsql zip \
    && apk del .build-deps

COPY --from=composer:2.10 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-progress
COPY . .
RUN composer dump-autoload --optimize \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && chmod -R ug+rwX storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh

EXPOSE 8000
HEALTHCHECK --interval=10s --timeout=3s --retries=6 \
    CMD wget -q -O - http://127.0.0.1:8000/up || exit 1

CMD ["./docker/entrypoint.sh"]
