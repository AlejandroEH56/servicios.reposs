# ADR-008: outbox transaccional en MySQL

Estado: Aceptada · Fecha: 2026-09-06

## Contexto

Notificaciones, proyecciones, auditoría y sincronización no deben ejecutarse dentro del request ni perderse tras el commit.

## Problema

Evitar el dual-write “guardar agregado y publicar evento” sin introducir un broker en Sprint 0.

## Opciones evaluadas

- Eventos síncronos post-save: bloquean y pueden perderse.
- Broker desde el inicio: más infraestructura y consistencia operacional.
- Outbox MySQL + worker: atomicidad local y evolución posterior.

## Decisión

Persistir agregado y `compartido_mensajes_salida` en la misma transacción. Un worker reclama lotes con lock seguro, publica a handlers idempotentes y marca `publicado_en`; retry exponencial y dead-letter tras umbral. El payload lleva `eventId`, tipo/version, aggregateId/version, occurredAt, actor y correlationId.

## Consecuencias

Hay consistencia eventual y posible entrega repetida; cada consumidor usa inbox/idempotencia. Se monitorizan edad del evento más antiguo, fallos e intentos. Un broker futuro consume la misma outbox.
