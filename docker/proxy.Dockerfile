FROM node:24.19.0-bookworm-slim@sha256:a9f5f7c91a432850b2a8a7797adf5eadb6c733ceed61167806cee7ea7fbc29df AS frontend
WORKDIR /build
COPY frontend/package.json frontend/package-lock.json ./
RUN npm install --global npm@11.17.0 --ignore-scripts && npm ci --ignore-scripts
COPY frontend/ ./
RUN npm run build -- --base-href=/portal/
FROM caddy:2.11.7-alpine@sha256:d8542f48d34a9cf4e4c11a478865229840e87e4c96ea3f439101f31a5d35f75f
RUN apk upgrade --no-cache
COPY --from=frontend /build/dist/servicios-ui/browser /srv/frontend
COPY public/ /srv/app/public/
COPY docker/Caddyfile /etc/caddy/Caddyfile
