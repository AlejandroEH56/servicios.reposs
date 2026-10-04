# PRE_SPRINT_1_CHECKLIST vigente para servicios.reposs

Evaluación: 2026-10-04. HEAD de referencia: `0546644746f1b2a281730a95c5fd8ef8bb48e2cc`; working tree modificado. **NO-GO**.

Este checklist reemplaza el diagnóstico de otro repositorio del 2026-09-27; [la copia original](historico/PRE_SPRINT_1_CHECKLIST_2026-09-27.md) permanece disponible con su checksum. La regla no cambia: sólo VERIFIED con evidencia vigente del mismo SHA satisface el Entry Gate. PASS local es avance técnico; no se marca una casilla global por una prueba sobre cambios aún sin commit.

| ID | Estado global | Evidencia local disponible | Condición exacta aún pendiente |
|---|---|---|---|
| B01 | PARTIAL | API /me y management strict, bundles y cliente Angular reproducible PASS | Breaking diff, contrato común ETag/If-Match aplicable y CI mismo SHA |
| B02 | PARTIAL | Estados y activación por grupo decididos; sesión y deny local PASS | Transiciones administrativas, archivo y revocación authorizationVersion/grupo, aprobación formal |
| B03 | PARTIAL | Outbox/lease/retry/FAILED/inbox/crash/replay técnico y concurrencia MySQL PASS | Autorización de replay, retención, métricas/umbrales y aprobación operativa |
| B04 | PARTIAL | Manifest ownership y fresh/adopción upgrade PASS, sin users duplicado | Aprobación institucional de frontera y migraciones de fases futuras |
| B05 | PARTIAL | Correo único, colisión/change y lookup tenant/objectId PASS | Perfilado legacy y procedimiento administrativo de correo reciclado |
| B06 | PARTIAL | Auditoría vacía convertida a ULID | Catálogos/retención del baseline y excepciones ADR para IDs técnicos |
| B07 | BLOCKED_INFO | Sólo callback HTTP loopback y sesión same-origin local | Topología representativa TLS, DNS/proxy/CORS y navegador E2E Secure/HttpOnly/SameSite/XSRF |
| B08 | PARTIAL | Configuración presente y metadata pública TLS verificada | App Registration/consentimiento/callback exacto, catálogo de usuarios allow/deny y claims redactados |
| B09 | PARTIAL | Adapter PKCE y Firebase/JWKS: suite sintética positiva/negativa PASS | Spike real del tenant y aceptación del candidato por arquitectura/seguridad |
| B10 | BLOCKED_INFO | Storage privado local usado por readiness | Driver productivo, AV/cuarentena/promoción y backup/restore |
| B11 | BLOCKED_INFO | Credenciales .env local no se publican; CA PHP corregido | Secret store, owners, expiración, rotación/revocación y secret scan |
| B12 | BLOCKED_INFO | Correlación HTTP y logs IAM redactados | Collector OTLP, señales correladas, política de fallo y redacción aprobada |
| B13 | PARTIAL | Workflows pinneados preparados, PHPStan y audits online PASS | CI ejecutada, breaking/SAST/secret/SBOM/image scans y required checks/branch rules |
| B14 | PARTIAL | MySQL 26.7.0: fresh/upgrade y principals temporales audit append-only PASS | MySQL objetivo, runtime/migrator persistentes separados, grants y runner aprobados |
| B15 | PARTIAL | Worker heartbeat, health tests y smoke HTTP live/ready=200 PASS | Worker supervisado, métricas y fault injection real en topología/Compose aprobada |
| BN-01 | PARTIAL | Laravel raíz + Angular + OpenAPI + scripts + workflows | Compose completo, servicios externos y clon limpio reproducible |
| BN-02 | PARTIAL | Fuentes existentes en HEAD y hashes históricos conservados | Versionar cambios/decisiones actuales, aprobación y verificación desde clon del SHA final |
| BN-03 | PARTIAL | Toolchain local y runner Windows preparados | Runner/Compose soportado y paridad CI/ambientes |
| BN-04 | BLOCKED_INFO | Existe baseline físico moderno vacío, no datos legacy | DDL/grants/volúmenes/perfilado legacy anonimizado con autorización y checksum |
| BN-05 | BLOCKED_INFO | Existe repositorio remoto configurado; sin administración remota realizada | Owners nominales/RACI/CODEOWNERS y readback de branch rules/reviews |

- [ ] Todos los B01–B15 y BN-01–BN-05 VERIFIED sobre el mismo SHA.
- [ ] Evidencia incluye fecha, herramienta/versión, comando, exit code, ambiente y artifact con checksum.
- [ ] Required checks y branch rules verificados en GitHub.
- [ ] PRE_SPRINT_1_GATE devuelve GO como primera línea.

Comprobaciones ejecutadas y límites: [VIABILIDAD_SERVICIOS_REPOSS.md](VIABILIDAD_SERVICIOS_REPOSS.md). Decisiones del solicitante: [IAM_ESTADOS.md](decisiones/IAM_ESTADOS.md). Prueba aislada autorizada: `phpunit.mysql.xml` fuerza APP_ENV=testing y servicios_moderno_test; jamás usar fresh sobre la base persistente.

Secuencia R0: decisiones IAM → ownership → contrato/cliente → fresh/upgrade/grants → outbox/heartbeat → suite local → topología/Entra/secretos/legacy y servicios externos → CI/owners → evidencia del SHA final → gate. La parte independiente de desarrollo ya está implementada y probada; los datos externos no se inventan ni se sustituye su validación con doubles.

Ejecutar `scripts/validate-pre-sprint1.ps1` para conservar logs de la secuencia y `node scripts/pre-sprint1-gate.mjs` para evaluar ESTADO_LOCAL_REPOSS.json. El manifest local conserva estados parciales; debe reemplazarse por evidencia CI completa para promoción. El gate comprueba formato/integridad/vigencia y SHA; la confianza/firma de artifacts y autoridad de approvals pertenecen al pipeline y branch rules aún pendientes.
