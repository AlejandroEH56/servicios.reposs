FROM composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer
FROM node:24.19.0-bookworm-slim@sha256:a9f5f7c91a432850b2a8a7797adf5eadb6c733ceed61167806cee7ea7fbc29df AS frontend
WORKDIR /build
COPY frontend/package.json frontend/package-lock.json ./
RUN npm install --global npm@11.17.0 --ignore-scripts && npm ci --ignore-scripts
COPY frontend/ ./
RUN npm run build -- --base-href=/portal/
FROM php:8.4.27-fpm-alpine@sha256:6b83fd767e1902ae4c89fe11f929f02e78bf769215c703060ac8a7c4be672c8c AS base
RUN apk upgrade --no-cache \
    && apk add --no-cache icu-libs libzip oniguruma tzdata unzip ca-certificates libpng libjpeg-turbo libwebp \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev oniguruma-dev libpng-dev libjpeg-turbo-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo_mysql mbstring intl zip opcache gd pcntl \
    && apk del .build-deps
RUN test "$(apk --print-arch)" = x86_64 \
    && wget -O /tmp/curl.apk https://dl-cdn.alpinelinux.org/alpine/edge/main/x86_64/curl-8.23.0-r0.apk \
    && wget -O /tmp/libcurl.apk https://dl-cdn.alpinelinux.org/alpine/edge/main/x86_64/libcurl-8.23.0-r0.apk \
    && printf '%s\n' '992fc37eda1cba79263c7850599ac94e19fd4dceec9cfdebef710256e04d60a4  /tmp/curl.apk' '8a7602f4625610c858a4583d9a731cb236a60f9fd0dd972b32dbb63a4eb94f1f  /tmp/libcurl.apk' | sha256sum -c - \
    && apk add --no-network /tmp/curl.apk /tmp/libcurl.apk \
    && rm /tmp/curl.apk /tmp/libcurl.apk \
    && php -r 'exit(curl_version()["version"] === "8.23.0" ? 0 : 1);'
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /srv/app
FROM base AS test
RUN apk add --no-cache poppler-utils font-liberation fontconfig
RUN printf 'memory_limit=256M\n' > /usr/local/etc/php/conf.d/test-memory.ini
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
