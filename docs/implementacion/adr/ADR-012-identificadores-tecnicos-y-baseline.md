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
| migrations, jobs, failed_jobs, job_batches, sessions | IDs/secuencias internos definidos por el framework; excepción técnica sin exposición como IDs del dominio |

Los catálogos de módulos futuros del baseline son compatibilidad temporal, no un permiso para generar nuevos IDs numéricos en el dominio. No se convierten aisladamente sin sus FKs/fixtures/ownership. Cada fase debe bloquear sus endpoints si la conversión no está verificada. La fundación fresh sólo crea IAM/Shared/framework y los IDs de sus entidades/eventos/auditoría son ULID. El DDL de referencia se conserva para reproducir la adopción histórica.

La frontera de fase fue aceptada expresamente por el responsable. B06 requiere las pruebas del candidato limpio; la conversión de catálogos/FKs permanece como condición de habilitación de cada módulo futuro.
## Frontera de fase aceptada

B06 se cierra para la fundación aceptando expresamente las excepciones técnicas de la tabla anterior y aplazando la conversión de catálogos/FKs exclusivamente hasta la habilitación de cada módulo. No se anuncia una conversión histórica ni se habilitan endpoints de esos módulos. Alcance del responsable: migrar funcionalidades con datos nuevos.

`modernization:verify-identifiers` valida por lectura el schema: rechaza IDs numéricos de dominio desconocidos; admite sólo las excepciones técnicas listadas; rechaza catálogo numérico o ausente si se habilita el módulo por `--enable-module` o por una ruta `/api/vN/<dominio>`; comprueba las FKs declaradas de catálogos habilitados. Las FKs lógicas no declaradas requieren el inventario/conversión de la fase. El control se ejecuta en pruebas SQLite, MySQL fresh y adopción del baseline. CI debe conservar esas pruebas como check obligatorio.

Antes de publicar Organization/Residencies/Planning/Inventory, ejecutar `modernization:verify-identifiers --enable-module=<module>` en el schema destino y completar fixtures/FKs lógicas de su fase. La aceptación del responsable permite entrar a Sprint 1 con IAM/Shared; no permite omitir esos controles posteriores. Aceptación expresa del responsable @AlejandroEH56 recibida durante esta ejecución: se aprueba esta frontera de fase y la clasificación de excepciones.
