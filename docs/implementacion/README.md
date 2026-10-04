# Paquete de arquitectura ejecutable

Fecha: 2026-09-06  
Estado: aprobado para iniciar Sprint 0, sujeto a los gates explícitos de versión e infraestructura.

Este paquete convierte las decisiones vigentes de discovery en reglas de construcción. No redefine dominios ni bounded contexts. Sus fuentes funcionales son:

- [Análisis DDD](../ANALISIS_DDD_MIGRACION.md)
- [Análisis de datos](../ANALISIS_BASE_DATOS_MODERNIZACION.md)
- [ER objetivo](../PROPUESTA_BASE_DATOS_ER.md)
- [DDL MySQL de referencia](../../database/mysql/001_create_servicios_moderno.sql)

## Orden de lectura y entregables

1. [ADRs](adr/README.md): decisiones obligatorias y sus consecuencias.
2. [Arquitectura Laravel](ARQUITECTURA_LARAVEL.md): estructura física, reglas de dependencias y composición.
3. [Contrato API REST](API_REST.md): recursos, endpoints, DTO, paginación, errores y versionado.
4. [IAM con Microsoft Entra ID](IAM_ENTRA_ID.md): login, callback, provisioning, claims, sesión y autorización.
5. [Arquitectura Angular](ANGULAR.md): features, rutas, guards, estado y cliente API.
6. [Seguridad](SEGURIDAD.md): threat model, controles OWASP y hardening.
7. [Plan de ejecución](PLAN_EJECUCION.md): vertical slices, ETL, CI/CD y checklists.
8. [OpenAPI inicial](api/openapi.yaml): contrato raíz que cada slice debe ampliar antes del código.

## Baseline técnico decidido

| Componente | Baseline de inicio | Regla |
|---|---|---|
| PHP | 8.3 o 8.4 | Fijar la misma minor en desarrollo, CI y producción. |
| Laravel | 13.x recomendado | Laravel 12 no es LTS y ya terminó bug fixes; sólo se admite temporalmente mediante excepción con actualización obligatoria antes del go-live. |
| MySQL | 8.0.16+ | `utf8mb4`, UTC, InnoDB, strict mode; el DDL actual usa `CHECK` aplicado desde 8.0.16. |
| Angular | 22.x recomendado | Cumple “Angular 20+”. Angular 20 termina LTS el 2026-11-28 y no es un baseline razonable para un desarrollo nuevo. |
| Node.js | 22 LTS compatible con Angular elegido | Pin en `.nvmrc`/Volta y CI. |
| API | REST `/api/v1`, OpenAPI 3.1 | JSON, Problem Details RFC 9457, compatible con la forma solicitada por RFC 7807. |
| Identidad | Entra ID, OIDC Authorization Code | Backend confidencial, sesión cookie con Sanctum; sin bearer token en almacenamiento web. |
| Cola inicial | Driver database + scheduler/worker | Outbox transaccional; broker sólo mediante ADR futuro. |

## Gate de versiones antes de crear repositorios nuevos

La documentación oficial vigente indica que Laravel 12 recibió correcciones de errores hasta el 2026-08-13 y recibirá seguridad hasta el 2027-02-24; no existe una categoría especial “Laravel 12 LTS”. Laravel 13 requiere PHP 8.3 y mantiene soporte de seguridad hasta 2028. Angular 20 permanece en LTS sólo hasta el 2026-11-28. Por ello:

1. crear el backend nuevo sobre Laravel 13.x;
2. crear el frontend sobre Angular 22.x;
3. si existe una restricción institucional que obligue Laravel 12, registrar una excepción con responsable y fecha máxima de actualización; no autorizar go-live después de su fin de seguridad.

Fuentes: [Laravel support policy](https://laravel.com/framework/docs/releases), [Laravel 12 release notes](https://laravel.com/framework/docs/12.x/releases), [Angular releases](https://angular.dev/reference/releases) y [compatibilidad Angular](https://angular.dev/reference/versions).

## Definition of Done transversal

Una capacidad sólo está terminada cuando tiene contrato OpenAPI aprobado, autorización server-side, pruebas unitarias/de integración/contrato, migración reversible o plan de roll-forward, auditoría cuando corresponda, telemetría, documentación operacional y reconciliación si consume datos legacy.
