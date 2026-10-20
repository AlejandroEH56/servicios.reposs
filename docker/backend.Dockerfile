FROM composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer
FROM node:24.19.0-bookworm-slim@sha256:a9f5f7c91a432850b2a8a7797adf5eadb6c733ceed61167806cee7ea7fbc29df AS frontend
WORKDIR /build
COPY frontend/package.json frontend/package-lock.json ./
RUN npm install --global npm@11.17.0 --ignore-scripts && npm ci --ignore-scripts
COPY frontend/ ./
RUN npm run build -- --base-href=/portal/
FROM php:8.4.26-fpm-alpine@sha256:78cd8de9970a9cd6ff4d98860a94eb5bf37dd2d5776b4785562dbfad01c29d5a AS base
RUN apk upgrade --no-cache \
    && apk add --no-cache icu-libs libzip oniguruma tzdata unzip ca-certificates libpng libjpeg-turbo libwebp \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev oniguruma-dev libpng-dev libjpeg-turbo-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo_mysql mbstring intl zip opcache gd \
    && apk del .build-deps
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /srv/app
FROM base AS test
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts
COPY . .
RUN mkdir -p .tools/phpstan-cache .tools/phpunit-cache storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache public/portal \
    && touch .phpunit.result.cache && chown -R www-data:www-data .tools .phpunit.result.cache storage bootstrap/cache public/portal
USER www-data
CMD ["php", "vendor/bin/phpunit"]
FROM base AS production
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./public
COPY --from=frontend /build/dist/servicios-ui/browser/index.html ./public/portal/index.html
COPY resources ./resources
COPY routes ./routes
COPY artisan ./artisan
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && rm /usr/local/bin/composer \
    && mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
COPY docker/php.ini /usr/local/etc/php/conf.d/modernization.ini
USER www-data
CMD ["php-fpm", "-F"]
