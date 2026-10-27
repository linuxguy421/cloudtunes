FROM composer:2 AS composer-bin
FROM php:8.4-cli-alpine
# Use PHP 8.4 to satisfy the PHP >=8.4.1 requirements in composer.lock.
# The official PHP image already provides DOM and the XML family.
RUN apk add --no-cache icu-libs libpq libzip sqlite-libs oniguruma libxml2 \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev sqlite-dev libzip-dev oniguruma-dev libxml2-dev \
 && docker-php-ext-install -j1 intl mbstring pdo_pgsql pdo_sqlite pcntl zip \
 && apk del .build-deps
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/control-plane
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts
COPY . .
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && composer dump-autoload --optimize --no-interaction \
 && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 8080
CMD ["php","artisan","serve","--host=0.0.0.0","--port=8080"]
