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


FROM golang:1.26.9-alpine@sha256:3082400e369fa24d5fc60bca20edab3f6d604e0c5a690ec66b295eff4dd87ade AS caddy-build
ENV CGO_ENABLED=0 GOTOOLCHAIN=local GOMAXPROCS=2 GOFLAGS=-p=2
WORKDIR /src
RUN apk add --no-cache patch
COPY docker/go-jose-v3-rsa-bounds.patch /tmp/go-jose-v3-rsa-bounds.patch
COPY docker/go-jose-v3-rsa-bounds_test.go /tmp/rsa_bounds_test.go
RUN go mod init servicios/caddy \
    && go get github.com/caddyserver/caddy/v2/cmd/caddy@v2.11.7 golang.org/x/net@v0.62.0 github.com/klauspost/compress@v1.20.2 github.com/go-jose/go-jose/v4@v4.1.6 \
    && go mod download github.com/go-jose/go-jose/v3@v3.0.5 \
    && cp -R /go/pkg/mod/github.com/go-jose/go-jose/v3@v3.0.5 /src/go-jose-v3 \
    && chmod -R u+w /src/go-jose-v3 \
    && printf '%s\n' '79f510a4b97fa2e34ec37932cab57e64dfdc9fa6c314654d1ccc2dd93898a532  /src/go-jose-v3/jwk.go' | sha256sum -c - \
    && patch --fuzz=0 -d /src/go-jose-v3 -p1 < /tmp/go-jose-v3-rsa-bounds.patch \
    && cp /tmp/rsa_bounds_test.go /src/go-jose-v3/rsa_bounds_test.go \
    && go mod edit -replace github.com/go-jose/go-jose/v3=./go-jose-v3 \
    && (cd /src/go-jose-v3 && go test ./...) \
    && go build -trimpath -ldflags="-s -w" -o /out/caddy github.com/caddyserver/caddy/v2/cmd/caddy \
    && go version -m /out/caddy
FROM node:24.19.0-bookworm-slim@sha256:a9f5f7c91a432850b2a8a7797adf5eadb6c733ceed61167806cee7ea7fbc29df AS frontend
WORKDIR /build
COPY frontend/package.json frontend/package-lock.json ./
RUN npm install --global npm@11.17.0 --ignore-scripts && npm ci --ignore-scripts
COPY frontend/ ./
RUN npm run build -- --base-href=/portal/
FROM caddy:2.11.7-alpine@sha256:d8542f48d34a9cf4e4c11a478865229840e87e4c96ea3f439101f31a5d35f75f AS production
ARG SECURITY_REFRESH=2026-11-24
RUN test -n "$SECURITY_REFRESH" && apk upgrade --no-cache && apk add --no-cache 'zlib>=1.4-r0' 'libcrypto3>=3.5.9-r1' 'libssl3>=3.5.9-r1' && apk del curl \
    && ! apk info -e curl && ! apk info -e libcurl
COPY --from=caddy-build /out/caddy /usr/bin/caddy
COPY --from=frontend /build/dist/servicios-ui/browser /srv/frontend
COPY public/ /srv/app/public/
COPY docker/Caddyfile /etc/caddy/Caddyfile

COPY --from=openssl-backport /patched/ /tmp/openssl-backport/
RUN apk add --no-cache --allow-untrusted /tmp/openssl-backport/libcrypto3-*.apk /tmp/openssl-backport/libssl3-*.apk \
    && rm /tmp/openssl-backport/*.apk && rmdir /tmp/openssl-backport \
    && apk info -e 'libcrypto3=3.5.9-r101' 'libssl3=3.5.9-r101'

