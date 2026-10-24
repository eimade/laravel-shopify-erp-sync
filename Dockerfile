# Image unique utilisée par les services app (php-fpm), worker (queue) et scheduler.
FROM composer:2 AS composer

FROM php:8.3-fpm-alpine

RUN apk add --no-cache icu-dev libzip-dev oniguruma-dev bash mariadb-client \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip bcmath pcntl opcache

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-uimadsync.ini

WORKDIR /var/www/html

# Dépendances d'abord pour profiter du cache Docker.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi \
    && php artisan filament:assets \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/uimadsync-entrypoint
RUN chmod +x /usr/local/bin/uimadsync-entrypoint

ENTRYPOINT ["uimadsync-entrypoint"]
CMD ["php-fpm"]
