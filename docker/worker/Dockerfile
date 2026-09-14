# syntax=docker/dockerfile:1
FROM php:8.5-cli-alpine

RUN apk add --no-cache libzip oniguruma unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libzip-dev oniguruma-dev \
    && docker-php-ext-install -j2 bcmath pcntl pdo_mysql mbstring zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY . .
RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs \
    && composer dump-autoload --no-dev --optimize --no-scripts \
    && php artisan package:discover --ansi
CMD ["php", "artisan", "horizon"]
