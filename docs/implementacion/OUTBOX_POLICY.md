# Outbox local y límites de operación

Fecha: 2026-10-04. Política técnica implementada; retención, autorización de replay y umbrales operativos institucionales pendientes.

Estados: PENDING → PROCESSING → PUBLISHED. Claim en transacción, lease de 60 s, dueño UUID, `FOR UPDATE SKIP LOCKED` en MySQL y orden ocurrido_en/id. Un lease vencido permite reclaim; un dueño anterior no puede publicar. Intentos aumentan en claim; fallos usan espera `min(300, 5*2^min(intentos-1,6))` segundos. Al quinto fallo pasa a FAILED, con fallido_en y error redactado HANDLER_FAILED; el mensaje no se elimina.

Envelope v1: eventId, type, version, aggregateId, aggregateVersion, occurredAt, actor y correlationId. El provisioning escribe identidad, vínculo, LOGIN_GRANTED y evento `iam.identity-linked.v1` en una transacción. No incluye correo, perfil ni tokens en el evento. El consumidor implementado registra IDENTITY_LINKED y su inbox. Inbox `(consumidor,id_evento)` y efectos se confirman con PUBLISHED en la misma transacción. Esto cubre consumidores de **la misma base/conexión**, no garantiza exactly-once en HTTP, SMTP ni brokers externos; esos adapters necesitan idempotencia propia.

El método interno replay admite sólo FAILED, reinicia intentos/estado y escribe OUTBOX_REPLAY. No se expone por HTTP ni existe comando de replay público. Su autorización por permiso, retención y runbook de operador deben resolverse antes del cierre completo B03. No reutilizarlo desde un endpoint sin comprobar autorización.

Ejecutar `php artisan outbox:work` como proceso supervisado junto al backend. `--once` procesa un batch y sirve como smoke. Cada batch exitoso, aun vacío, actualiza el heartbeat compartido; TTL 120 s. DB y almacenamiento privado, además de ese heartbeat, forman readiness. Ejecutar una vez no mantiene el heartbeat: tras 120 s readiness devuelve 503 si el worker no sigue corriendo. No incluye Entra ni OTLP.

`phpunit.mysql.xml` verifica dos conexiones reales, filas bloqueadas, leases, inbox, rollback de efectos, retry, FAILED, replay y permisos de auditoría. Los principals de prueba son temporales y se eliminan en finally. Estos grants no sustituyen la configuración del runtime/migrator del ambiente persistente.
