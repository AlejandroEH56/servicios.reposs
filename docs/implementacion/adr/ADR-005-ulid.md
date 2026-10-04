# ADR-005: ULID como identificador nuevo

Estado: Aceptada · Fecha: 2026-09-06

## Contexto

La migración necesita IDs independientes de los enteros legacy y ordenables aproximadamente por tiempo.

## Problema

Elegir una clave interoperable entre API, PHP, Angular y procesos ETL.

## Opciones evaluadas

- `BIGINT AUTO_INCREMENT`: compacto, filtra volumen/orden y complica cargas distribuidas.
- UUID v4: interoperable, pero aleatorio para índices.
- ULID: 26 caracteres, generación en aplicación y orden temporal.

## Decisión

Usar ULID canónico en mayúsculas, generado por aplicación, almacenado como `CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`. La API lo representa como string. IDs legacy sólo aparecen en `migracion_mapa_ids`.

## Consecuencias

Los índices son mayores que `BIGINT`; no se infiere seguridad ni orden total del ULID. Relojes se monitorizan y ninguna regla de negocio depende exclusivamente de su timestamp.
