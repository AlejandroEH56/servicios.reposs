FROM php:8.4.27-fpm-alpine@sha256:6b83fd767e1902ae4c89fe11f929f02e78bf769215c703060ac8a7c4be672c8c AS openssl-backport
RUN apk add --no-cache alpine-sdk perl linux-headers doas
ENV JOBS=2 MAKEFLAGS=-j2 HARNESS_JOBS=2
WORKDIR /build/openssl
COPY docker/openssl-APKBUILD ./APKBUILD
COPY docker/openssl-*.patch docker/openssl-fips.post-install ./
RUN printf '%s\n' 'permit nopass root as root' > /etc/doas.conf \
    && abuild-keygen -a -i -n \
    && (abuild -F -r -P /out > /build/openssl-check.log 2>&1 || { tail -n 100 /build/openssl-check.log; exit 1; }) \
    && tail -n 35 /build/openssl-check.log \
    && mkdir /patched \
    && find /out -type f \( -name 'openssl-3.5.9-r101.apk' -o -name 'libcrypto3-3.5.9-r101.apk' -o -name 'libssl3-3.5.9-r101.apk' \) -exec cp {} /patched/ \; \
    && test "$(find /patched -type f | wc -l)" -eq 3


FROM composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer
FROM node:24.19.0-bookworm-slim@sha256:a9f5f7c91a432850b2a8a7797adf5eadb6c733ceed61167806cee7ea7fbc29df AS frontend
WORKDIR /build
COPY frontend/package.json frontend/package-lock.json ./
RUN npm install --global npm@11.17.0 --ignore-scripts && npm ci --ignore-scripts
COPY frontend/ ./
RUN npm run build -- --base-href=/portal/
FROM php:8.4.27-fpm-alpine@sha256:6b83fd767e1902ae4c89fe11f929f02e78bf769215c703060ac8a7c4be672c8c AS base
ARG SECURITY_REFRESH=2026-11-24
RUN test -n "$SECURITY_REFRESH" && apk upgrade --no-cache \
    && apk add --no-cache 'zlib>=1.4-r0' 'libcrypto3>=3.5.9-r1' 'libssl3>=3.5.9-r1' \
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
COPY --from=openssl-backport /patched/ /tmp/openssl-backport/
RUN apk add --no-cache --allow-untrusted /tmp/openssl-backport/*.apk \
    && rm /tmp/openssl-backport/*.apk && rmdir /tmp/openssl-backport \
    && apk info -e 'libcrypto3=3.5.9-r101' 'libssl3=3.5.9-r101'

RUN apk add --no-cache poppler-utils font-liberation fontconfig
RUN printf 'memory_limit=256M\n' > /usr/local/etc/php/conf.d/test-memory.ini
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts
COPY . .
RUN mkdir -p .tools/phpstan-cache .tools/phpunit-cache storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache public/portal \
    && touch .phpunit.result.cache && chown -R www-data:www-data .tools .phpunit.result.cache storage bootstrap/cache public/portal
USER www-data
CMD ["php", "vendor/bin/phpunit"]
FROM base AS mysql-runtime
RUN set -eux; \
    apk add --no-cache --virtual .php-rebuild $PHPIZE_DEPS \
        argon2-dev curl-dev gnu-libiconv-dev libsodium-dev libxml2-dev \
        linux-headers oniguruma-dev openssl-dev readline-dev zlib-dev; \
    printf '%s  %s\n' "$PHP_SHA256" /usr/src/php.tar.xz | sha256sum -c -; \
    docker-php-source extract; \
    cd /usr/src/php; \
    rm -f /usr/include/iconv.h; \
    export CFLAGS="$PHP_CFLAGS" CPPFLAGS="$PHP_CPPFLAGS" LDFLAGS="$PHP_LDFLAGS" \
        PHP_BUILD_PROVIDER='https://github.com/docker-library/php' PHP_UNAME='Linux'; \
    ./configure --build="$(dpkg-architecture --query DEB_BUILD_GNU_TYPE)" \
        --sysconfdir=/usr/local/etc --with-config-file-path="$PHP_INI_DIR" \
        --with-config-file-scan-dir="$PHP_INI_DIR/conf.d" --enable-option-checking=fatal \
        --with-mhash --with-pic --enable-mbstring --enable-mysqlnd --with-password-argon2 \
        --with-sodium=shared --without-pdo-sqlite --without-sqlite3 \
        --with-curl --with-iconv=/usr --with-openssl --with-readline --with-zlib \
        --disable-phpdbg --disable-cgi --enable-fpm --with-fpm-user=www-data --with-fpm-group=www-data; \
    make -j 4; \
    make install; \
    strip --strip-all /usr/local/bin/php /usr/local/sbin/php-fpm; \
    cd /; \
    docker-php-source delete; \
    apk del --no-network .php-rebuild 'so:libsqlite3.so.0'; \
    ! apk info -e sqlite-libs; \
    php -r 'exit(!extension_loaded("pdo_sqlite") && !extension_loaded("sqlite3") && extension_loaded("pdo_mysql") && extension_loaded("pcntl") ? 0 : 1);'; \
    php-fpm -t

FROM mysql-runtime AS production
COPY --from=openssl-backport /patched/ /tmp/openssl-backport/
RUN apk add --no-cache --allow-untrusted /tmp/openssl-backport/*.apk \
    && rm /tmp/openssl-backport/*.apk && rmdir /tmp/openssl-backport \
    && apk info -e 'libcrypto3=3.5.9-r101' 'libssl3=3.5.9-r101'

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
