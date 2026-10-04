# ADR-007: RBAC local más políticas contextuales

Estado: Aceptada · Fecha: 2026-09-06

## Contexto

Los permisos dependen del rol y también de residente, laboratorio, ownership y estado del agregado.

## Problema

Impedir que roles globales o guards de UI sustituyan la autorización de objeto y función.

## Opciones evaluadas

- Sólo grupos/roles Entra: acopla negocio al directorio y no expresa alcance.
- Sólo RBAC local: no cubre ownership/contexto.
- RBAC local + policies/ABAC: permiso estable y predicados por recurso.

## Decisión

Entra autentica y puede aportar grupos de aprovisionamiento; IAM local es autoridad de autorización. Cada endpoint ejecuta una Policy Laravel con permiso (`residencias.evidencias.revisar`) y alcance (`resident_id`, `laboratory_id`, ownership, estado). Denegar por defecto. La UI sólo oculta navegación; nunca concede acceso.

## Consecuencias

Se requiere matriz permiso–acción–alcance, tests positivos/negativos y auditoría de grants. No se confía en `preferred_username`, nombre ni puesto para decidir acceso. La eventual sincronización de grupos contempla overage de Entra.

Fuente: [claims y límites de grupos de Entra](https://learn.microsoft.com/en-us/entra/identity-platform/access-token-claims-reference).
