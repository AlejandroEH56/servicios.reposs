# ADR-009: storage privado detrás de un port

Estado: Aceptada · Fecha: 2026-09-06

## Contexto

Expedientes, plantillas, imágenes y documentos emitidos tienen sensibilidad y retención distintas.

## Problema

Evitar rutas públicas, BLOB en MySQL y acoplamiento del dominio al filesystem legacy.

## Opciones evaluadas

- BLOB MySQL: transacciones simples, backups y crecimiento costosos.
- Directorio público local: simple e inseguro.
- Laravel Filesystem sobre almacenamiento privado: portable entre disco/NFS/S3 compatible.

## Decisión

Definir `FileStorage` en Shared/Application. Producción usa disco privado fuera de `public`; si hay más de un nodo, NFS institucional con locking/backup probado o S3 compatible. La DB guarda clave opaca, nombre original, MIME detectado, tamaño, SHA-256 y estado de escaneo. Descarga sólo por controller autorizado; URLs firmadas con TTL cuando el driver lo soporte.

## Consecuencias

DB y binarios requieren backup/restauración coordinados. Upload usa staging, límites, detección MIME, antivirus y promoción atómica. Los snapshots emitidos no se reemplazan; una corrección crea otra versión.
