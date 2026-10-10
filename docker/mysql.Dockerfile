FROM golang:1.27.2-alpine@sha256:85dc1069ac644ea3c527b177303a406eb3358192816cd7f9e5848eb658851673 AS gosu
RUN GOBIN=/out CGO_ENABLED=0 go install github.com/tianon/gosu@6456aaa0f3c854d199d0f037f068eb97515b7513
FROM mysql:26.7.0@sha256:9d48c42f8341068f199116dfccb919b607c99765b5c61e549a548a43033471a4
RUN microdnf update -y && microdnf remove -y mysql-shell && microdnf clean all
COPY --from=gosu /out/gosu /usr/local/bin/gosu
RUN gosu nobody true
