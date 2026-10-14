# PHP 8.4 FPM image used by the app, queue worker and scheduler containers.
FROM php:8.4-fpm-alpine AS base
RUN apk add --no-cache libpq-dev libjpeg-turbo-dev libpng-dev freetype-dev libzip-dev icu-dev oniguruma-dev $PHPIZE_DEPS \
 && docker-php-ext-configure gd --with-jpeg --with-freetype \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql gd exif zip intl mbstring opcache pcntl bcmath \
 && apk del $PHPIZE_DEPS
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/backend

FROM base AS deps
COPY backend/composer.json backend/composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

FROM base AS app
COPY --from=deps /var/www/backend/vendor ./vendor
COPY backend/ ./
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
 && mkdir -p storage/framework/{cache,sessions,views} storage/logs storage/app/private bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache
USER www-data
