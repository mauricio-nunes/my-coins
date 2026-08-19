FROM composer:2 AS composer

FROM php:8.3-cli-bookworm

ARG APP_UID=1000
ARG APP_GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends git libicu-dev libzip-dev unzip \
    && docker-php-ext-install intl pcntl zip \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/xdebug.ini /usr/local/etc/php/conf.d/99-xdebug.ini

RUN groupadd --gid "$APP_GID" app \
    && useradd --uid "$APP_UID" --gid app --create-home --shell /bin/bash app

WORKDIR /var/www/html
USER app

EXPOSE 8000 9003
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
