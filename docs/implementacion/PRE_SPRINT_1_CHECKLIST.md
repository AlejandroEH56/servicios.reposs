# Checklist vigente de servicios.reposs

Actualizado: 2026-10-09. Responsable @AlejandroEH56. Se cerraron técnicamente los pendientes B02/B06/B09/B10/B11 mediante recorridos reales, aceptación expresa y recuperación portable. La evaluación ejecutable del commit limpio está en `artifacts/PRE_SPRINT_1_EVALUACION_FINAL_20261009.json`; debe validar veinte bloqueantes y siete checks del mismo SHA. El resultado del dossier, no el estado de este resumen versionado, autoriza iniciar Sprint 1.

| ID | Criterio de cierre y evidencia requerida del candidato |
|---|---|
| B01 | OpenAPI lint/bundle/cliente reproducible, breaking diff y CI |
| B02 | Estados/política IAM aceptados; ciclo real grupo/suspensión/archivo/rehabilitación y regresión de sesiones/versión |
| B03 | Outbox lease/SKIP LOCKED/inbox/retry/FAILED/replay, fallos y retención/métricas/heartbeat |
| B04 | Ownership de migraciones, fresh/adopción y CI MySQL |
| B05 | Tenant/objectId, correo único, colisión/nulo/cambio/contacto auditado; datos nuevos aceptados |
| B06 | ADR-012 aceptado; verifier SQLite/MySQL fresh/baseline/schema aislado; catálogo/FK convertido antes de activar módulo |
| B07 | Sesión HTTPS/cookies/CSRF/Chromium y login Entra real |
| B08 | App Registration/callbacks/consentimiento y allow/deny reales; metadata/Graph TLS |
| B09 | Spike OIDC aceptado; claims/firma/JWKS/state/replay/PKCE/nonce y límites temporales probados |
| B10 | Private storage/AV/cuarentena/EICAR/fallo cerrado; fixture real y cold restore SQL/files/AV/TLS/app/worker; RPO/RTO/schedule/política |
| B11 | ACL/roles, scans, rotación DB/APP_KEY y Entra anterior rechazada; backup age fuera de DPAPI y custodia externa confirmada |
| B12 | OTLP/exemplars correlacionados, redacción, retención y fallo fail-open |
| B13 | Calidad, locks/audits/SAST/secretos/SBOM/provenance/scans por imagen, workflows del mismo SHA |
| B14 | Arquitectura y suites backend/MySQL/Linux/Docker/TLS verificadas |
| B15 | Health/readiness/fallos/recuperación y métricas/worker |
| BN-01 | Control de versiones limpio, fuentes/locks/candidato reproducibles |
| BN-02 | Framework/runtime instalados y versiones compatibles comprobadas |
| BN-03 | Entra real y ambientes/Docker preparados |
| BN-04 | Migración funcional con datos nuevos aceptada; código legacy disponible y sin dump histórico necesario |
| BN-05 | Responsable único/CODEOWNERS, main protegida y readback cinco contexts con strict/enforce-admins |

- [x] Decisiones IAM, correo, alcance de datos y B06/B09 aceptadas por el responsable.
- [x] Retirada/restauración de grupo, suspensión/desactivación y login nuevo de rehabilitación ejecutados con cuenta de ensayo.
- [x] Credencial Entra sustituta probada; anterior eliminada y rechazada.
- [x] Respaldo cifrado portable, Linux cold restore y custodia externa del primer snapshot confirmados.
- [x] Schedule y retención/capacidad definidos y leídos en el equipo actual.
- [ ] Para cada release: veinte VERIFIED, evidencia vigente del SHA limpio, required checks completos y gate GO/exit 0.

Pruebas destructivas sólo en testing/servicios_moderno_test o topologías aisladas. La cuenta habitual y el origen de datos se conservan. Custodia nube procede de confirmación del responsable; no se inspeccionaron permisos del proveedor. Recuperación acreditada en contenedores Linux nuevos del mismo equipo, sin DPAPI y sin puertos publicados; repetir en host destino antes de producción pública. No confundir esa condición de promoción con un bloqueo de implementación de Sprint 1.

[Plan paso a paso](PLAN_CIERRES_PENDIENTES_PRE_SPRINT_1.md), [runbook](OPERACION_RECUPERACION_PORTABLE.md), [spike aceptado](SPIKE_OIDC_CIERRE_B09.md), [ADR-012](adr/ADR-012-identificadores-tecnicos-y-baseline.md) y [checklist histórico](historico/PRE_SPRINT_1_CHECKLIST_2026-09-27.md).