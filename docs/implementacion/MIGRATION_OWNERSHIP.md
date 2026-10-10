# Frontera de migraciones implementada

Actualizado: 2026-10-05. El backend Laravel está en la raíz de servicios.reposs; el legacy permanece en servicios.proyecto. No se crea un segundo backend anidado. El solicitante es responsable de arquitectura/datos y autorizó los ensayos; B04/BN-05 requieren evidencia del candidato y reglas remotas, no nombrar otro responsable.

| Tablas / patrón | Owner técnico | Fase | Comportamiento actual |
|---|---|---|---|
| sessions, cache, cache_locks, jobs, job_batches, failed_jobs, migrations | Framework Laravel | Sprint 0 | Migraciones estándar; sessions referencia ULID lógico, sin users ni password_reset_tokens |
| iam_identidades, iam_cuentas_externas | IAM | Fundación de autenticación | Crear en fresh; adoptar si existen. Rechazar estados legacy sin conversión aprobada; conservar correo unique y vínculo externo unique |
| compartido_registros_auditoria | Shared | Sprint 0 | Crear ULID; convertir PK numérica sólo si tabla vacía. Tabla con datos exige conversión separada revisada |
| compartido_mensajes_salida, compartido_bandeja_entrada | Shared | Sprint 0 | Ampliar outbox vacío con estados/lease; inbox nueva. Outbox legacy con mensajes exige adopción de envelopes revisada |
| iam_roles, iam_permisos, iam_identidad_roles, iam_rol_permisos | IAM autorización | Sprint 2 | Baseline existente conservado; fresh no crea estas tablas hasta su fase. /me informa listas vacías |
| organizacion_* | Organization | Sprint 2 | Conservar baseline; futuras migraciones pertenecen exclusivamente a este módulo |
| compartido_archivos_almacenados | Shared | Capacidad privada inicial | Adoptada/creada mediante migración 2026-10-08; port de cuarentena/scanner y descarga del dueño activo, sin upload de negocio |
| compartido_claves_idempotencia, compartido_politicas_retencion, compartido_registros_retencion | Shared | Fase de capacidad respectiva | Conservar baseline; no duplicar ni anunciar API todavía |
| migracion_mapa_ids | Migración | Shadow/cutover | Conservar baseline; no cargar datos legacy automáticamente |
| residencias_*, publicaciones_*, planificacion_*, laboratorios_*, inventario_* | Módulo homónimo | Fases de negocio de PLAN_EJECUCION | Conservar baseline; fuera del fresh mínimo de esta fundación |

El DDL `database/mysql/001_create_servicios_moderno.sql` sigue como referencia inmutable del diseño original. No se ejecuta contra desarrollo: esa base ya tenía el baseline. El ensayo upgrade retira únicamente CREATE DATABASE/USE del SQL y lo ejecuta en **servicios_moderno_test**, luego aplica migraciones. Fresh crea sólo la fundación actual; no se interpreta como entrega de los módulos futuros.

Migraciones de adopción son roll-forward. Su `down()` rechaza reversión destructiva; cualquier rollback real requiere backup y plan específico. `migrate:fresh`, `migrate:refresh` y `db:wipe` sólo se permiten en APP_ENV=testing y SQLite :memory: o MySQL servicios_moderno_test. No ejecutar `composer setup` sobre una base persistente sin revisar sus pasos.

B06 sigue parcial: auditoría ya usa ULID, pero los catálogos y registros de retención del baseline conservan AUTO_INCREMENT. ADR-012 clasifica los ocho casos baseline, la tabla técnica de mapeo y excepciones del framework. La conversión de catálogos/FK pertenece a la entrega de su módulo y debe verificarse antes de habilitarlo. El perfilado actual encontró los cuatro esquemas legacy sin tablas; un ensayo de upgrade del baseline no sustituye datos históricos.
