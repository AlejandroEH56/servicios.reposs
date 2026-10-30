FROM golang:1.26.9-alpine@sha256:3082400e369fa24d5fc60bca20edab3f6d604e0c5a690ec66b295eff4dd87ade AS caddy-build
ENV CGO_ENABLED=0 GOTOOLCHAIN=local GOMAXPROCS=2 GOFLAGS=-p=2
WORKDIR /src
RUN apk add --no-cache patch
COPY docker/go-jose-v3-rsa-bounds.patch /tmp/go-jose-v3-rsa-bounds.patch
COPY docker/go-jose-v3-rsa-bounds_test.go /tmp/rsa_bounds_test.go
RUN go mod init servicios/caddy \
    && go get github.com/caddyserver/caddy/v2/cmd/caddy@v2.11.7 golang.org/x/net@v0.60.0 github.com/go-jose/go-jose/v4@v4.1.6 \
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
RUN apk upgrade --no-cache && apk del curl \
    && ! apk info -e curl && ! apk info -e libcurl
COPY --from=caddy-build /out/caddy /usr/bin/caddy
COPY --from=frontend /build/dist/servicios-ui/browser /srv/frontend
COPY public/ /srv/app/public/
COPY docker/Caddyfile /etc/caddy/Caddyfile
