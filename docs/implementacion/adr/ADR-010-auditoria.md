# ADR-010: auditoría append-only y retención gobernada

Estado: Aceptada · Fecha: 2026-09-06

## Contexto

Validaciones, autorizaciones, movimientos, cierres, grants y depuración requieren atribución e historia.

## Problema

Distinguir logs técnicos de evidencia de negocio y evitar que una actualización destruya la decisión anterior.

## Opciones evaluadas

- Logs de aplicación: mutables y sin integridad referencial.
- Auditoría genérica por triggers: oculta semántica y contradice la decisión de no usar triggers.
- Historial de dominio + registro técnico append-only: intención explícita y consulta operable.

## Decisión

Cada agregado crítico conserva historial semántico en su módulo y registra actor, acción, sujeto, fecha, correlationId y metadata mínima en `compartido_registros_auditoria`. No guardar secretos ni documento completo. Actualización/borrado directo de auditoría se niega al usuario de aplicación. Retención 24/60/120 meses cambia estado; la depuración es manual, autorizada, simulable y auditable.

## Consecuencias

Crece almacenamiento y se necesitan particionado/archivado evaluados por volumen. La auditoría no sustituye snapshots legales ni observabilidad. Acceso de lectura es un permiso separado.
