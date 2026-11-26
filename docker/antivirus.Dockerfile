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


FROM clamav/clamav@sha256:ebec5bc138401b36ae987caa1a3fa3c3b2a21ed3d51f0bfa5852825e663e67b0
ARG SECURITY_REFRESH=2026-11-24
RUN test -n "$SECURITY_REFRESH" && apk upgrade --no-cache && apk add --no-cache 'zlib>=1.4-r0' 'libcrypto3>=3.5.9-r1' 'libssl3>=3.5.9-r1'

COPY --from=openssl-backport /patched/ /tmp/openssl-backport/
RUN apk add --no-cache --allow-untrusted /tmp/openssl-backport/libcrypto3-*.apk /tmp/openssl-backport/libssl3-*.apk \
    && rm /tmp/openssl-backport/*.apk && rmdir /tmp/openssl-backport \
    && apk info -e 'libcrypto3=3.5.9-r101' 'libssl3=3.5.9-r101'

