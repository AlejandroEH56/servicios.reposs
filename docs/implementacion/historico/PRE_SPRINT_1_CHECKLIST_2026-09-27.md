# PRE_SPRINT_1_CHECKLIST

Fecha de evaluación inicial: 2026-09-27  
Commit inspeccionado: `9c81ea7cec64bb76592974dff5eb549b190cd893`  
Regla: ningún requisito se marca hasta que su resultado sea `VERIFIED` y la evidencia corresponda al mismo SHA. Los fallos reproducidos se registran, pero no satisfacen el requisito.

## Fuentes y gobernanza

- [ ] Las fuentes de arquitectura, ADR, OpenAPI y DDL están versionadas en el commit evaluado.
  - evidencia: `git ls-files docs database` debe listar todas las fuentes aprobadas.
  - comando/prueba: `git ls-files docs database` y clon limpio con verificación SHA-256.
  - resultado: FAIL — actualmente no devuelve archivos; `git status` muestra `?? docs/` y `?? database/`.
  - responsable: FALTANTE — owner técnico/repositorio no documentado.
  - referencia documental: `PLAN_EJECUCION.md:223,270`; BN-02 del plan de resolución.

- [ ] Existe RACI nominal y CODEOWNERS con autoridad para arquitectura, datos, seguridad, frontend y operación.
  - evidencia: RACI aprobado, `CODEOWNERS` y readback de branch rules.
  - comando/prueba: inspección de `CODEOWNERS` y configuración GitHub autorizada.
  - resultado: BLOCKED_INFO — sólo hay roles genéricos; no personas/teams.
  - responsable: FALTANTE — ownership nominal no documentado.
  - referencia documental: `PLAN_EJECUCION.md:223,270`; BN-05.

## Fundación reproducible

- [ ] El repositorio contiene `backend/`, `frontend/`, `openapi/`, `docker/`, `scripts/` y `.github/` separados del legacy.
  - evidencia: paths versionados y estructura revisada.
  - comando/prueba: comprobación de paths y `git ls-files`.
  - resultado: MISSING — ninguno existe.
  - responsable: FALTANTE — owner técnico no documentado.
  - referencia documental: `SPRINT_0_ENTREGABLE.md:162-228`; BN-01.

- [ ] Laravel 13 con PHP 8.3/8.4 instala desde lockfile y ejecuta tests.
  - evidencia: `backend/composer.json`, `composer.lock`, logs de install/test.
  - comando/prueba: `composer validate --strict`; `composer install`; `php artisan test`.
  - resultado: NOT_RUN — backend, PHP y Composer ausentes en el host inspeccionado.
  - responsable: FALTANTE — owner backend no documentado.
  - referencia documental: ADR-002; README implementación.

- [ ] Angular 22 standalone instala desde lockfile, compila en strict y ejecuta tests.
  - evidencia: `frontend/package.json`, lockfile, `angular.json`, logs build/test.
  - comando/prueba: `npm ci`; `npm run lint`; `npm test`; `npm run build`.
  - resultado: NOT_RUN — frontend inexistente; Node local 24.19.0 no es pin del proyecto.
  - responsable: FALTANTE — owner frontend no documentado.
  - referencia documental: ADR-003; `ANGULAR.md`; BN-03.

- [ ] Compose levanta todos los servicios desde un clon limpio y los healthchecks convergen.
  - evidencia: config renderizada, imágenes por digest, logs de healthy.
  - comando/prueba: `docker compose config --quiet`; `docker compose up -d --build`; inspección health.
  - resultado: NOT_RUN — Compose inexistente y Docker no disponible.
  - responsable: FALTANTE — owner DevOps/operación no documentado.
  - referencia documental: `SPRINT_0_ENTREGABLE.md:510-623`; BN-03.

## OpenAPI y cliente Angular

- [ ] Redocly strict finaliza con cero errores y cero warnings.
  - evidencia: log de CI ligado al SHA.
  - comando/prueba: `npx --yes @redocly/cli@2.14.3 lint <contratos-desplegables> --format stylish`.
  - resultado: FAIL — contrato actual: exit 1, 18 errores y 14 warnings.
  - responsable: FALTANTE — owner API no documentado.
  - referencia documental: ADR-004; B01.

- [ ] Health está en contrato management no versionado y el API desplegable no anuncia operaciones futuras.
  - evidencia: bundles `management.yaml`/`api.yaml` y catálogo de rutas desplegadas.
  - comando/prueba: Redocly bundle + comparación de paths contra router.
  - resultado: FAIL — contrato actual compone `/api/v1/health/*` e incluye módulos futuros.
  - responsable: FALTANTE — owner API/operación no documentado.
  - referencia documental: `PLAN_EJECUCION.md:14`; `API_REST.md:255-256`; B01.

- [ ] RFC 9457, correlation ID, ETag/If-Match y respuestas comunes están modelados y probados.
  - evidencia: schemas/headers, contract tests positivos/negativos.
  - comando/prueba: lint custom + runner de contrato.
  - resultado: PARTIAL — Problem Details existe; ETag/If-Match y correlation uniforme faltan.
  - responsable: FALTANTE — owner API no documentado.
  - referencia documental: `API_REST.md:14,51-93`; B01.

- [ ] `/me` está aprobado, fuertemente tipado e incluye scopes y expiración de sesión sin tokens.
  - evidencia: schema aprobado y contract tests.
  - comando/prueba: bundle/lint y test de respuesta fixture.
  - resultado: BLOCKED_INFO — enum IAM contradictorio; faltan `scopes` y `sessionExpiresAt` en el schema actual.
  - responsable: FALTANTE — owner IAM/API no documentado.
  - referencia documental: `API_REST.md:103,112`; B01/B02.

- [ ] Breaking diff bloqueante y generación Angular son reproducibles y no ensucian Git.
  - evidencia: logs oasdiff/generator/build y `git diff --exit-code`.
  - comando/prueba: `oasdiff breaking`; generator fijado; `npm run build`; `git diff --exit-code`.
  - resultado: NOT_RUN — configuración y frontend ausentes.
  - responsable: FALTANTE — owner API/frontend no documentado.
  - referencia documental: `PLAN_EJECUCION.md:230,245`; B01.

## IAM y datos previos a OIDC

- [ ] Existe un único enum IAM con significado, transiciones, login permitido, revocación y archivo aprobados.
  - evidencia: decision record y tests de máquina de estados.
  - comando/prueba: búsqueda de vocabularios + suite de transición.
  - resultado: BLOCKED_INFO — IAM y DDL se contradicen.
  - responsable: FALTANTE — owner negocio/IAM no documentado.
  - referencia documental: `IAM_ENTRA_ID.md:91-99`; DDL:158-166; B02.

- [ ] La identidad externa usa `(tid,oid)` y la política de correo contempla cambio, alias, duplicado y reciclaje.
  - evidencia: decisión, perfilado anonimizado y fixtures.
  - comando/prueba: reconciliación y tests de colisión/cambio de correo.
  - resultado: BLOCKED_INFO — unique global condicionado, sin política ni datos.
  - responsable: FALTANTE — DBA/negocio aparecen como roles, owner nominal ausente.
  - referencia documental: ADR-006; análisis DB:118,483; B05.

- [ ] Los ocho `AUTO_INCREMENT` están cambiados o cubiertos por excepción ADR aprobada.
  - evidencia: schema/migrations y ADR aplicable.
  - comando/prueba: `rg -n 'AUTO_INCREMENT' database backend` + tests ULID.
  - resultado: PARTIAL — clasificados; tres disposiciones requieren decisión y cinco catálogos requieren cambio.
  - responsable: FALTANTE — owner arquitectura/datos no documentado.
  - referencia documental: ADR-005; B06.

- [ ] La frontera de migraciones Sprint 0/1/2 está aprobada y no duplica tablas.
  - evidencia: manifest tabla→módulo→sprint y test fresh/upgrade.
  - comando/prueba: inspección manifest; `migrate:fresh` sólo en DB efímera; schema diff.
  - resultado: READY_TO_FIX — existe propuesta, no aprobación ni migrations.
  - responsable: FALTANTE — owner técnico/datos no documentado.
  - referencia documental: `PLAN_EJECUCION.md:16,26`; B04.

- [ ] DDL físico legacy, grants, volúmenes y perfilado IAM están disponibles con checksum y anonimización.
  - evidencia: baseline autorizado y reporte de calidad.
  - comando/prueba: extracción `--no-data`, `information_schema`, queries de duplicados/huérfanos.
  - resultado: BLOCKED_INFO — sólo existe evidencia lógica/PNG y código.
  - responsable: DBA es rol revisor documentado; persona/team no documentado.
  - referencia documental: análisis DB:17,23,325,386; BN-04.

## Base de datos, Outbox y auditoría

- [ ] Migrations Laravel fresh y upgrade pasan en la versión MySQL objetivo.
  - evidencia: logs CI con versión exacta y schema diff.
  - comando/prueba: `php artisan migrate:fresh --env=testing --force` únicamente en contenedor desechable `servicios_moderno_test`; ensayo upgrade separado.
  - resultado: NOT_RUN — no migrations, backend, Docker ni MySQL objetivo acreditado.
  - responsable: FALTANTE — owner backend/DBA no documentado.
  - referencia documental: `ARQUITECTURA_LARAVEL.md:68-69,372`; B14.

- [ ] Runtime y migrator tienen grants mínimos separados; runtime no ejecuta DDL.
  - evidencia: `SHOW GRANTS` redactado y tests deny.
  - comando/prueba: suite `DatabaseGrants` contra usuarios no productivos.
  - resultado: BLOCKED_INFO — principals/grants no proporcionados.
  - responsable: DBA como rol; owner nominal ausente.
  - referencia documental: `SEGURIDAD.md:49,169`; B14.

- [ ] Auditoría es append-only para runtime.
  - evidencia: INSERT permitido; UPDATE/DELETE denegados y registrados según runbook.
  - comando/prueba: integration test de grants.
  - resultado: NOT_RUN — sólo existe tabla en DDL de referencia.
  - responsable: FALTANTE — owner seguridad/DBA no documentado.
  - referencia documental: ADR-010; B14.

- [ ] Outbox tiene estado, disponibilidad, lease, intentos, publicación, fallo y error redactado.
  - evidencia: migration y tests de columnas/transiciones.
  - comando/prueba: schema assertion + suite Outbox.
  - resultado: FAIL — DDL actual carece de estado, disponibilidad, lease y fallo terminal.
  - responsable: FALTANTE — owner backend/operación no documentado.
  - referencia documental: ADR-008; DDL:41-55; B03.

- [ ] Claim concurrente, recuperación, retry/backoff, poison/dead-letter, inbox y replay están verificados.
  - evidencia: suite MySQL concurrente y métricas.
  - comando/prueba: tests de dos workers, crash points y duplicados.
  - resultado: NOT_RUN — no worker ni tests.
  - responsable: FALTANTE — owner backend/operación no documentado.
  - referencia documental: ADR-008; B03.

## Topología, Entra y secretos

- [ ] La topología exacta por ambiente define SPA/API origins, DNS, TLS, proxy, CORS, session domain y callback.
  - evidencia: manifiesto/diagrama aprobado.
  - comando/prueba: validación de configuración y endpoints no productivos.
  - resultado: BLOCKED_INFO — dominio final/topología desconocidos.
  - responsable: FALTANTE — owner operación no documentado.
  - referencia documental: `SEGURIDAD.md:48,112-113`; B07.

- [ ] Cookies Secure/HttpOnly/SameSite, XSRF, CORS y redirects pasan E2E bajo TLS representativo.
  - evidencia: resultados E2E y headers redactados.
  - comando/prueba: navegador real, casos de origin/token/cookie inválidos.
  - resultado: NOT_RUN — depende de topología y aplicaciones inexistentes.
  - responsable: FALTANTE — owners backend/frontend/seguridad no documentados.
  - referencia documental: ADR-006; B07.

- [ ] Tenant y App Registration no productivos, callback exacto y conectividad HTTPS están acreditados.
  - evidencia: manifiesto no secreto y preflight.
  - comando/prueba: consulta de metadata OIDC/TLS desde backend de prueba.
  - resultado: BLOCKED_INFO — no hay evidencia en repo/docs.
  - responsable: FALTANTE — owner IAM no documentado.
  - referencia documental: `IAM_ENTRA_ID.md:18-38`; B08.

- [ ] Credencial/certificado está en secret store con owner, expiración, rotación y revocación.
  - evidencia: referencia no secreta y ensayo de rotación.
  - comando/prueba: secret scan + preflight de referencia en ambiente no productivo.
  - resultado: BLOCKED_INFO — secret store/procedimiento no elegidos.
  - responsable: FALTANTE — owner seguridad/operación no documentado.
  - referencia documental: `SEGURIDAD.md:46-47,113`; B11.

- [ ] Claims redactados y usuarios/casos de prueba aprobados están disponibles.
  - evidencia: catálogo de casos allow/deny y muestras sin PII/tokens.
  - comando/prueba: validación de esquema de fixtures.
  - resultado: BLOCKED_INFO.
  - responsable: FALTANTE — owner IAM/negocio no documentado.
  - referencia documental: `IAM_ENTRA_ID.md:76-84,134-143`; B08.

- [ ] El cliente OIDC fue seleccionado por spike con todos los controles obligatorios.
  - evidencia: matriz de candidatos, versiones y suite positiva/negativa.
  - comando/prueba: harness contra tenant/app no productivos.
  - resultado: BLOCKED_INFO — no se ejecutó y no se presupone ganador.
  - responsable: FALTANTE — owner arquitectura/seguridad no documentado.
  - referencia documental: ADR-006; B09.

## Storage, observabilidad y readiness

- [ ] Driver productivo de storage, backup/restore y servicio AV están decididos y probados.
  - evidencia: decision record, restore y corpus AV controlado.
  - comando/prueba: upload privado, MIME/hash, EICAR, cuarentena/promoción y restore.
  - resultado: BLOCKED_INFO — NFS/S3/otro y AV no definidos.
  - responsable: FALTANTE — owner operación/seguridad no documentado.
  - referencia documental: ADR-009; `SEGURIDAD.md:51,113`; B10.

- [ ] Collector local OTLP y destino productivo separado están configurados.
  - evidencia: config, endpoints referenciados y señales recibidas.
  - comando/prueba: emitir trace/metric/log de smoke y correlacionarlos.
  - resultado: BLOCKED_INFO — no config ni destino.
  - responsable: FALTANTE — operación/SOC figuran como consumidores; owner nominal ausente.
  - referencia documental: ADR-011; `SEGURIDAD.md:52,113`; B12.

- [ ] Redacción PII/tokens y conducta ante caída del collector están aprobadas y probadas.
  - evidencia: tests de redacción y fault injection.
  - comando/prueba: fixtures con campos sensibles sintéticos; collector detenido en Compose local.
  - resultado: BLOCKED_INFO — política fail-open/fail-closed de telemetría no documentada.
  - responsable: FALTANTE — owner seguridad/operación no documentado.
  - referencia documental: ADR-011; B12.

- [ ] `/health/live` responde 200 sin depender de DB/storage/OTLP/Entra.
  - evidencia: contract test con dependencias detenidas.
  - comando/prueba: fault injection sólo sobre Compose local desechable.
  - resultado: NOT_RUN — endpoint no implementado.
  - responsable: FALTANTE — owner backend/operación no documentado.
  - referencia documental: `API_REST.md:255`; B15.

- [ ] `/health/ready` prueba únicamente DB, storage y heartbeat Outbox documentados; retorna 503 y se recupera.
  - evidencia: contract/fault tests 200→503→200 sin detalles sensibles.
  - comando/prueba: detener/iniciar cada dependencia del Compose local y validar timeout.
  - resultado: BLOCKED_INFO/PARTIAL — contrato existe de forma incorrecta; no implementación ni heartbeat definido.
  - responsable: FALTANTE — owner backend/operación no documentado.
  - referencia documental: `API_REST.md:256`; B15.

## CI/CD y supply chain

- [ ] Workflows backend, frontend, OpenAPI y seguridad existen y pasaron en el SHA evaluado.
  - evidencia: required checks verdes y logs/artifacts.
  - comando/prueba: ejecución PR/CI completa.
  - resultado: MISSING — `.github/` no existe.
  - responsable: FALTANTE — owner DevSecOps no documentado.
  - referencia documental: `PLAN_EJECUCION.md:225-251`; B13.

- [ ] Actions y containers están pinneados por SHA/digest.
  - evidencia: workflow/Compose review y scanner de pins.
  - comando/prueba: policy check de referencias mutables.
  - resultado: NOT_RUN — artefactos inexistentes.
  - responsable: FALTANTE — owner DevSecOps no documentado.
  - referencia documental: `SEGURIDAD.md:129`; B13.

- [ ] Composer/npm audit y dependency review cumplen la política de vulnerabilidades.
  - evidencia: logs SCA y excepciones temporales firmadas si existen.
  - comando/prueba: `composer audit`; `npm audit`; dependency review.
  - resultado: NOT_RUN — no lockfiles modernos ni workflows.
  - responsable: FALTANTE — owner seguridad no documentado.
  - referencia documental: `PLAN_EJECUCION.md:234,247`; B13.

- [ ] SAST PHP/TypeScript y CodeQL aplicable pasan sin hallazgos bloqueantes.
  - evidencia: Semgrep/Larastan/PHPStan y CodeQL para lenguajes soportados presentes.
  - comando/prueba: jobs de análisis fijados.
  - resultado: NOT_RUN — herramientas/workflows ausentes.
  - responsable: FALTANTE — owner seguridad no documentado.
  - referencia documental: `SPRINT_0_ENTREGABLE.md:642-655`; B13.

- [ ] Secret scan cubre repo/historial pertinente y PRs sin exponer secretos.
  - evidencia: gitleaks PASS y política de incidente.
  - comando/prueba: job gitleaks pinneado.
  - resultado: NOT_RUN — gitleaks/workflow ausentes.
  - responsable: FALTANTE — owner seguridad no documentado.
  - referencia documental: `SEGURIDAD.md:129`; B13.

- [ ] SBOM e image scan se generan para artefactos por SHA/digest.
  - evidencia: CycloneDX/SPDX, scan y checksum/provenance.
  - comando/prueba: jobs SBOM/image scan sobre imagen construida una sola vez.
  - resultado: NOT_RUN — imágenes/artifacts/workflows inexistentes.
  - responsable: FALTANTE — owner DevSecOps/seguridad no documentado.
  - referencia documental: `PLAN_EJECUCION.md:234,239`; B13.

- [ ] Required checks y branch protection impiden merge/deploy con gates fallidos.
  - evidencia: readback de reglas GitHub y prueba de PR fallida.
  - comando/prueba: API/UI administrativa autorizada de GitHub.
  - resultado: BLOCKED_INFO — permisos/configuración no disponibles localmente.
  - responsable: FALTANTE — owner repositorio no documentado.
  - referencia documental: `PLAN_EJECUCION.md:223`; B13/BN-05.

## Gate final

- [ ] Todos los bloqueantes B01–B15 y BN-01–BN-05 están `VERIFIED` en el mismo SHA.
  - evidencia: manifest firmado por CI con enlaces a cada prueba.
  - comando/prueba: `scripts/pre-sprint1-gate`.
  - resultado: FAIL — actualmente no hay ninguna fila VERIFIED.
  - responsable: FALTANTE — responsable del Release Gate no documentado nominalmente.
  - referencia documental: `PLAN_RESOLUCION_PRE_SPRINT_1.md`, secciones 5, 18 y 19.

- [ ] `PRE_SPRINT_1_GATE` devuelve `GO` como primera línea.
  - evidencia: log CI ligado al commit y required check verde.
  - comando/prueba: `scripts/pre-sprint1-gate`.
  - resultado: NO-GO — script aún no existe y las condiciones obligatorias fallan.
  - responsable: FALTANTE — responsable del Release Gate no documentado nominalmente.
  - referencia documental: `PLAN_RESOLUCION_PRE_SPRINT_1.md`, sección 18.

Resultado vigente:

**NO-GO**
