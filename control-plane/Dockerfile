FROM composer:2 AS composer-bin
FROM php:8.3-cli-alpine
RUN apk add --no-cache icu-libs libpq libzip sqlite-libs oniguruma libxml2 \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev sqlite-dev libzip-dev oniguruma-dev libxml2-dev \
 && docker-php-ext-install -j"$(nproc)" dom intl mbstring pdo_pgsql pdo_sqlite pcntl simplexml xml xmlreader xmlwriter zip \
 && apk del .build-deps
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/control-plane
COPY composer.json ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts
COPY . .
RUN composer dump-autoload --optimize --no-interaction \
 && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 8080
CMD ["php","artisan","serve","--host=0.0.0.0","--port=8080"]
