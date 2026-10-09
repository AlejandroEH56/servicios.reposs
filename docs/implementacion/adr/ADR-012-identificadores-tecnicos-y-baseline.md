# ADR-012: IDs técnicos y adopción del baseline

Estado: decisión de implementación del responsable; fecha: 2026-10-05. Complementa ADR-005 sin cambiar el identificador público del dominio.

El solicitante designó al desarrollador actual como responsable de las decisiones y autorizó preparar los ambientes de la migración.

| Caso AUTO_INCREMENT del baseline | Resolución |
|---|---|
| compartido_registros_auditoria | ULID en la fundación; migración aplicada. Datos históricos numéricos requieren conversión revisada, nunca pérdida de registros |
| migracion_mapa_ids | Ledger técnico interno; se admite el contador como clave técnica, con unicidad de origen y ULID destino. No se expone como IdentityId ni otro ID de dominio |
| organizacion_niveles_academicos | Convertir catálogo/FKs a ULID en Sprint 2 antes de habilitar escrituras modernas |
| residencias_modalidades, residencias_sectores, residencias_ramos | Convertir catálogo/FKs a ULID al implementar Residencias, antes de activar sus APIs |
| planificacion_tipos_dia_inhabil | Convertir catálogo/FKs a ULID en la fase Planificación |
| inventario_detalles_corte_mensual | ULID en la fase Inventario, con reconciliación del ID anterior |
| migrations, jobs, failed_jobs, job_batches | IDs/secuencias internos definidos por el framework; excepción técnica sin exposición como IDs del dominio |

Los catálogos de módulos futuros del baseline son compatibilidad temporal, no un permiso para generar nuevos IDs numéricos en el dominio. No se convierten aisladamente sin sus FKs/fixtures/ownership. Cada fase debe bloquear sus endpoints si la conversión no está verificada. La fundación fresh sólo crea IAM/Shared/framework y los IDs de sus entidades/eventos/auditoría son ULID. El DDL de referencia se conserva para reproducir la adopción histórica.

Esta clasificación resuelve la decisión de diseño; B06 conserva estado PARTIAL hasta evidencia y aceptación del SHA final y, para tablas futuras, su conversión en la fase indicada.