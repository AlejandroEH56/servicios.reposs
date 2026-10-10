FROM golang:1.26.9-alpine@sha256:3082400e369fa24d5fc60bca20edab3f6d604e0c5a690ec66b295eff4dd87ade AS build
ENV CGO_ENABLED=0 GOTOOLCHAIN=local GOMAXPROCS=2 GOFLAGS=-p=2
WORKDIR /build
COPY docker/collector-builder.yaml ./builder.yaml
RUN go install go.opentelemetry.io/collector/cmd/builder@v0.161.0 \
    && builder --config builder.yaml \
    && go version -m /out/otelcol-contrib
FROM otel/opentelemetry-collector-contrib@sha256:fd328de2552466ad78385e1b1289c3f2402b1c45f265b252aab1955b42845ac1
COPY --from=build /out/otelcol-contrib /otelcol-contrib
