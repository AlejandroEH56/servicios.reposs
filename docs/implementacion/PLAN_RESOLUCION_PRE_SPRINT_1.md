# Plan de resolución de bloqueantes pre-Sprint 1

Fecha de inspección: 2026-09-27  
Alcance: evidencia local del repositorio `servicios.proyecto`; no se implementó Sprint 1, OIDC ni lógica de negocio.  
Estado de partida respetado: **GO condicionado para Sprint 0; NO-GO para Sprint 1**.

## 1. Dictamen ejecutivo

Sprint 1 permanece en **NO-GO**. La evidencia demuestra que Sprint 0 está diseñado, pero no materializado como proyecto moderno ejecutable: no existen `backend/`, `frontend/`, `openapi/`, `docker/`, `scripts/` ni `.github/`; el `composer.json` raíz pertenece a CodeIgniter 4; no hay lockfile Angular, Compose, migraciones Laravel, health endpoints Laravel, worker Outbox, collector, tests arquitectónicos ni pipelines.

El único contrato OpenAPI existente está en `docs/implementacion/api/openapi.yaml`, no en la estructura objetivo, y una ejecución real de Redocly 2.14.3 finalizó con exit code 1, **18 errores y 14 advertencias**. El DDL de referencia no satisface ADR-008 para Outbox, contradice `IAM_ENTRA_ID.md` en estados IAM, mantiene una unicidad de correo cuya política está expresamente condicionada, y contiene ocho `AUTO_INCREMENT` que requieren clasificación frente a ADR-005.

Además, `docs/` y `database/` completos aparecen como `??` en `git status` y `git ls-files docs database` no devuelve archivos. Por tanto, un clon de `HEAD 9c81ea7cec64bb76592974dff5eb549b190cd893` no obtiene ni la documentación inspeccionada ni el DDL objetivo. Esto impide reproducibilidad y trazabilidad aunque los documentos sean correctos en el workspace actual.

No existe evidencia suficiente para ejecutar remediaciones de infraestructura institucional ni para iniciar un spike real contra Entra. Faltan, entre otros, topología/origins, DNS/TLS, tenant y App Registration no productivos, callback exacto, mecanismo de credencial, secret store, storage/antivirus, destino OTLP, grants MySQL, permisos GitHub, datos/DDL legacy verificables y owners nominales. Esos faltantes no impiden preparar los artefactos locales independientes, pero sí impiden cerrar el Entry Gate.

## 2. Estado real de Sprint 0

| Capacidad | DOCUMENTADO | IMPLEMENTADO | VERIFICADO | Estado real |
|---|---:|---:|---:|---|
| ADR-001 a ADR-011 | Sí, todos con estado aceptado; ADR-002 aceptada con gate | No aplica como código | Existencia y contenido inspeccionados | DOCUMENTADO |
| Estructura moderna | Sí, en `SPRINT_0_ENTREGABLE.md` | No | Ausencia comprobada en filesystem | FALTANTE |
| Laravel 13 / PHP 8.3–8.4 | Sí | No | No hay `backend/composer.json`; `php` y `composer` no están disponibles en el host de inspección | FALTANTE |
| Angular 22 | Sí | No | No existen `frontend/package.json`, lockfile ni `angular.json`; el host tiene Node 24.19.0, no un pin del proyecto | FALTANTE |
| OpenAPI First | Sí | Parcial: un contrato documental monolítico | Redocly ejecutado: FAIL, 18 errores/14 warnings | PARTIAL |
| MySQL objetivo | Sí: DDL de referencia requiere 8.0.16+ | No hay migraciones Laravel | No se ejecutó contra un MySQL efímero objetivo; Docker no está disponible | PARTIAL |
| CQRS ligero | Sí | No | Sin código ni tests | FALTANTE |
| Outbox | Sí | Sólo DDL de referencia insuficiente | Inspección de columnas; sin ejecución/concurrencia | PARTIAL |
| Auditoría append-only | Sí | Sólo tabla en DDL de referencia | Sin grants ni prueba de prohibición UPDATE/DELETE | PARTIAL |
| Storage privado / AV | Port y controles documentados | No | Driver productivo y AV desconocidos | BLOCKED |
| Observabilidad | ADR y diseño documentados | No | Sin collector/config/destino ni test de redacción/fail-open | BLOCKED |
| Sanctum / sesión | Documentado | No | Topología y prueba XSRF/TLS ausentes | BLOCKED |
| Infraestructura local | Propuesta Compose documentada | No | `docker` no disponible y no existe Compose | FALTANTE |
| CI/CD y supply chain | Diseño documentado | No | No existe `.github/`; ningún check ejecutable del proyecto moderno | FALTANTE |
| Tests arquitectónicos | Ejemplos documentados | No | No existe `backend/tests/Architecture` | FALTANTE |
| Health live/ready | Contrato y comportamiento esperados documentados | No | No existen rutas/controladores; contrato actual los versiona accidentalmente | PARTIAL |
| Entra ID | Estrategia aprobada | No, y no debe implementarse en este plan | Prerrequisitos externos no acreditados | BLOCKED |

Conclusión: **ninguna capacidad ejecutable de Sprint 0 puede declararse cerrada**. Lo verificado hasta ahora son documentos, ausencias, contradicciones y el fallo reproducido de OpenAPI; no la fundación técnica.

## 3. Evidencia inspeccionada

### Inventario de fuentes

| Fuente | Evidencia relevante |
|---|---|
| `docs/implementacion/SPRINT_0_ENTREGABLE.md` | Dictamen condicionado, estructura propuesta, riesgos, DoD y Entry Gate; es diseño, no implementación. |
| `docs/implementacion/PLAN_EJECUCION.md` | Frontera por sprints, CI/CD, precondiciones y checklists. Contiene el solapamiento IAM Sprint 0/Sprint 1. |
| `docs/implementacion/ARQUITECTURA_LARAVEL.md` | Monolito modular, Domain puro, ownership de migraciones, no ejecutar baseline y migraciones en paralelo, dependencias y tests. |
| `docs/implementacion/API_REST.md` | `/api/v1`, RFC 9457, `If-Match`, `/me`, health no versionado y generación OpenAPI-first. |
| `docs/implementacion/ANGULAR.md` | Standalone, Signals/RxJS, cliente generado, facades, cookie/XSRF y prohibición de bearer tokens persistidos. |
| `docs/implementacion/IAM_ENTRA_ID.md` | Prerrequisitos Entra, validaciones OIDC, `(tid,oid)`, estados IAM y sesión. |
| `docs/implementacion/SEGURIDAD.md` | Threat model y tabla explícita de decisiones/infraestructura pendientes. |
| `docs/implementacion/api/openapi.yaml` | Contrato 3.1.1 actual; rutas futuras, `/me`, health bajo server `/api/v1`, schemas genéricos. |
| `docs/implementacion/adr/ADR-001...ADR-011` | Decisiones aceptadas que no deben cambiarse silenciosamente. |
| `docs/ANALISIS_BASE_DATOS_MODERNIZACION.md` | IAM por identidad externa, correo único condicionado, ausencia de DDL físico legacy y perfilado pendiente. |
| `docs/ANALISIS_DDD_MIGRACION.md` | Bounded contexts, legacy auth, deuda, oleadas y evidencia legacy pendiente. |
| `docs/PROPUESTA_BASE_DATOS_ER.md` | ER objetivo y advertencia de no ejecutar directamente en producción. |
| `database/mysql/001_create_servicios_moderno.sql` | Baseline de referencia, Outbox, IAM y ocho `AUTO_INCREMENT`. No es una migración Laravel. |
| `composer.json` y `composer.lock` raíz | Proyecto CodeIgniter 4 legacy, no Laravel. |
| `app/Controllers/OAuthlogin/*`, modelos legacy y `app/Config/*` | Legacy usa correo y/o datos del proveedor en flujos distintos; no existe en el repo la clase `Config\OAuth` referenciada. No aporta credenciales reutilizables. |
| `tests/` | Tests legacy CI4; no son tests de la fundación Laravel/Angular. |

### Comandos y resultados verificables ejecutados

| Comando/inspección | Resultado |
|---|---|
| `git rev-parse --verify HEAD` | `9c81ea7cec64bb76592974dff5eb549b190cd893` |
| `git status --short` | `?? database/` y `?? docs/` |
| `git ls-files docs database` | Sin salida: esas fuentes no están en `HEAD` |
| Comprobación de rutas objetivo | Todos los paths modernos enumerados resultaron inexistentes |
| `npx --yes @redocly/cli@2.14.3 lint docs/implementacion/api/openapi.yaml --format stylish` | exit 1; 18 errores `operation-summary`, 14 warnings |
| Inventario de toolchain | Node 24.19.0, npm 11.17.0, Git 2.51.1; `php`, `composer`, `docker`, `java`, `semgrep`, `gitleaks`, `syft`, `trivy`, `codeql` ausentes |
| Cliente/servidor MySQL local | 26.7.0; no es evidencia de la versión objetivo ni se usó para ejecutar el DDL |
| `rg` sobre DDL | Ocho `AUTO_INCREMENT`; Outbox sin state/lease/dead-letter; IAM con enum distinto y correo unique |

Hashes SHA-256 de las fuentes centrales inspeccionadas:

| Archivo | SHA-256 |
|---|---|
| `SPRINT_0_ENTREGABLE.md` | `AC5536398A6C0B904E59FA142AC3268148D41508A118763D90B4D7BF5206700F` |
| `PLAN_EJECUCION.md` | `F2BB7C170A708EF53E587DAEDCBA23B9FE5F20BF705BC6D4FE91A931E9D0A1B6` |
| `ARQUITECTURA_LARAVEL.md` | `DED34B910C90841D180E55CA3623A199F302BB935B3908BD24B69870D9E8D16F` |
| `API_REST.md` | `DF582B1862E885FACD0A032864EF38C8A5529784E915E2B1F84A1AF7D3FB591E` |
| `ANGULAR.md` | `B40DDEA4F13B91827DBD6BBD4877A9A529C7646D318FACB35F4E44E6A6EABE1A` |
| `IAM_ENTRA_ID.md` | `3A72D46B70FC079C96D3433AB8D9238EF28CD14BAE88EFC1666AFC8D598638BB` |
| `SEGURIDAD.md` | `46A59F1D1FA073E097818D11BF72D7D62B86922505D7844673DB30EB0322A943` |
| `api/openapi.yaml` | `1DE9EB8C1EB32272D01B4A67BEB45962C94FAE19B49A460669AA3C47150A0D0B` |
| `001_create_servicios_moderno.sql` | `7143C200E23264F73ED57DF4A0569A35763C6EE618B247EDBE20EC48974B9428` |

## 4. Diferencia entre documentado, implementado y verificado

Se aplican estas reglas de evidencia:

| Término | Regla |
|---|---|
| DOCUMENTADO | Existe una afirmación en Markdown/ADR/contrato. No prueba archivos ejecutables ni comportamiento. |
| IMPLEMENTADO | Existe código/config/migración/workflow en la estructura aprobada y está versionado. No prueba que funcione. |
| VERIFICADO | Una prueba/comando reproducible sobre el artefacto implementado termina satisfactoriamente y su resultado está vinculado al mismo commit SHA. |
| FALTANTE | El artefacto requerido no existe en el workspace o no está versionado cuando debe viajar en el repositorio. |
| CONTRADICTORIO | Dos fuentes vigentes prescriben valores, fronteras o comportamientos incompatibles. |
| BLOQUEADO | No puede cerrarse sin una decisión, dato, acceso o infraestructura que no existe en las fuentes inspeccionadas. |

Ejemplos aplicados:

- Que `SPRINT_0_ENTREGABLE.md` contenga un `composer.json` recomendado no significa que exista `backend/composer.json`.
- Que el DDL contenga `compartido_registros_auditoria` no demuestra append-only: faltan migración ejecutada, usuario runtime y prueba de grants.
- Que ADR-011 requiera OTLP no demuestra collector local ni backend institucional.
- El lint Redocly sí está VERIFICADO como **fallido**; el requisito “contrato en verde” sigue abierto.
- El baseline SQL es referencia de aceptación según `ARQUITECTURA_LARAVEL.md`; no debe ejecutarse además de migraciones Laravel.

## 5. Matriz de bloqueantes

| ID | Bloqueante | Fuente | Evidencia actual | Estado | Severidad | Dependencias | Acción | Artefactos afectados | Prueba de cierre | Criterio GO | Información faltante |
|---|---|---|---|---|---|---|---|---|---|---|---|
| B01 | OpenAPI desplegable y reproducible | ADR-004; API REST; PLAN; S0 | Contrato monolítico no versionado; Redocly exit 1, 18/14; health queda bajo `/api/v1`; operaciones futuras; schemas genéricos; `/me` incompleto; sin ETag/If-Match ni generation/diff | PARTIAL | Bloqueante / P0 | B02, B07, B15, BN-01, BN-02 | Separar contrato desplegable/management/planned, tipar, corregir lint y activar generación/diff | `openapi/*`, cliente generado, workflows | lint/bundle 0; diff; cliente compila; árbol limpio; contract tests | Todos los gates contractuales PASS en el SHA | Enum IAM; origin/servers aprobados |
| B02 | Estados IAM contradictorios | IAM:91; DDL:158,166 | Documento usa `PENDIENTE/ACTIVA/SUSPENDIDA/DESACTIVADA`; DDL usa `PENDIENTE/ACTIVO/DESHABILITADO/ARCHIVADO`; no hay matriz de transiciones | BLOCKED_INFO | Bloqueante / P0 | BN-05 | Decisión funcional explícita y actualización simultánea de documento, OpenAPI, dominio y migración | IAM doc, ADR aclaratorio si cambia decisión, OpenAPI, migración/tests | test de máquina de estados y login deny; diff sin vocabularios divergentes | Un enum y semántica aprobados y VERIFICADOS | Significados, transiciones, reversibilidad y reglas de sesión |
| B03 | Outbox no implementable con garantías ADR-008 | ADR-008; PLAN; DDL:41-55 | Sólo `publicado_en`, `intentos`, `ultimo_error`; faltan state, available_at, lease, failed_at; no inbox por consumidor ni dead-letter; no worker | READY_TO_FIX | Bloqueante / P0 | B04, B14, BN-01 | Diseñar migración, worker, inbox, retry/backoff, poison/dead-letter, replay y métricas | Shared migrations/code/tests/config | pruebas concurrentes, crash/recovery, duplicado, poison y replay PASS | ADR-008 completo y observado | Umbral/backoff/replay policy debe documentarse dentro del paquete |
| B04 | Ownership de migraciones Sprint 0/1/2 | PLAN:16,26,30-37; ARCH:68-69 | Sprint 0 y 1 reclaman IAM base; no hay migraciones Laravel | READY_TO_FIX | Bloqueante / P0 | B02, B03, B06, B14 | Aprobar frontera explícita y descomponer baseline una sola vez | backlog, migration map, providers | `migrate:fresh` + upgrade path; no tablas duplicadas | Manifest de ownership aprobado y tests PASS | Owner nominal de aprobación no documentado |
| B05 | Correo unique frente a identidad `(tid,oid)` | ADR-006; IAM:68,78,96-97; análisis DB:118,483; DDL:157,164,180 | `(provider, tenant, subject)` es unique; correo también unique aunque la política está “condicionada”; legacy busca por correo; sin perfilado de colisiones/mutabilidad | BLOCKED_INFO | Bloqueante / P0 | B02, B08, BN-04, BN-05 | Perfilar datos y aprobar política de alias/correo nulo/reciclado; no cambiar schema antes | decisión IAM, migration, reconciliation, tests | fixtures de correo cambiado/duplicado/alias y reconciliación por `(tid,oid)` | Correo nunca sustituye identidad y colisiones tienen conducta aprobada | Política institucional y perfil de datos |
| B06 | `AUTO_INCREMENT` frente a ADR-005 | ADR-005; DDL | Ocho casos identificados; sin clasificación aprobada/excepción ADR | PARTIAL | Importante / P1 | B04, BN-05 | Resolver por categoría; si se mantiene cualquier ID nuevo autoincremental, registrar ADR CHANGE REQUIRED | ADR-005/ADR nuevo, ER, DDL referencia, migrations | búsqueda 0 incompatibilidades no justificadas + tests de ULID | Cada caso tiene decisión aprobada y schema coincide | Decisión para secuencias técnicas/detalles/catálogos |
| B07 | Same-origin/TLS/XSRF/topología | ADR-006; IAM; Seguridad:48,112-113 | Sólo same-site/top-level domain está decidido; dominio final, origins, CORS, proxy, TLS y callback exacto no existen | BLOCKED_INFO | Bloqueante / P0 | B08, B11, B15 | Proveer diagrama y endpoints por ambiente; ejecutar matriz cookie/XSRF/TLS | config runtime, Apache, Sanctum/CORS/session, tests | navegador real: cookies, CSRF, redirect y CORS PASS bajo TLS representativo | Topología objetivo probada, sin tokens en Angular | Hosts/origins/DNS/TLS/proxy/callback |
| B08 | Prerrequisitos Entra no acreditados | IAM:18-38; PLAN:270-276 | No hay tenant no productivo, App Registration, callback, credencial/certificado, claims redactados, usuarios de prueba ni conectividad acreditados | BLOCKED_INFO | Bloqueante / P0 | B07, B11, BN-05 | Recibir manifiesto no secreto y evidencias de disponibilidad; no implementar login | dossier de ambiente/approvals, no secretos en repo | preflight TLS/OIDC metadata y pruebas con usuarios aprobados, ejecutadas posteriormente | Todos los prerrequisitos VERIFIED antes de iniciar Sprint 1 | Todos los datos enumerados |
| B09 | Cliente OIDC no seleccionado por evidencia | ADR-006; IAM callback; S0:327 | `composer.json` legacy usa `league/oauth2-client`, pero no es decisión del backend nuevo; no hay spike ni matriz comparativa | BLOCKED_INFO | Importante / P1 | B07, B08, B11, BN-01 | Spike de Entry Gate sin implementar login productivo; probar todos los controles obligatorios | informe spike, lockfile temporal/branch, test harness | casos positivos y negativos de code/PKCE/state/nonce/claims/JWKS/clock/replay | Candidato aprobado con evidencia; ningún ganador se presupone | Tenant/app de prueba, candidatos aprobados, criterios de mantenimiento |
| B10 | Storage productivo y antivirus | ADR-009; Seguridad:51,113 | Port documentado; driver local/NFS/S3 y AV pendientes; sin código | BLOCKED_INFO | Importante / P1 | B11, B15, BN-05 | Implementar port/fake/local aislado; decisión externa para adapter productivo y scanner | Shared ports/adapters/config/runbooks | upload EICAR controlado, MIME/hash, cuarentena, promoción, restore | Adapter/AV objetivo y fallos probados | Driver, capacidad, HA, backup, AV, SLA |
| B11 | Secret store | IAM:23; Seguridad:46-47,113 | Requisito documentado; proveedor, acceso, rotación y ownership ausentes | BLOCKED_INFO | Bloqueante / P0 | B07, B08, B10, B12, B14 | Decisión institucional; aplicación sólo consume referencias/runtime env | decisión, runbook, CI/deploy config | secreto no aparece en repo/log/artifact; rotación ensayada | Store y flujo de rotación VERIFIED | Tecnología, paths/references, ACL, owners, expiración |
| B12 | Observabilidad local/productiva | ADR-011; Seguridad:52,113 | Sólo diseño; sin SDK/config/collector local/destino productivo; protocolo/fail-open/redacción sin prueba | BLOCKED_INFO | Importante / P1 | B07, B11, BN-01 | Implementar collector local e instrumentación mínima; documentar destino productivo separado | backend config, collector config, dashboards/tests | trace/log/metric correlados; PII redactada; caída collector con conducta aprobada | Señales y política de fallo VERIFIED | Endpoint/protocolo/TLS/auth/retención/fail-open y campos PII |
| B13 | CI/CD y supply chain inexistentes | PLAN:211-251; Seguridad:129; S0:624-655 | No `.github`; sin SHA pins/digests/audits/SAST/SCA/CodeQL/gitleaks/SBOM/image scan/artifact SHA | PARTIAL | Bloqueante / P0 | BN-01, BN-02, B14 | Crear workflows con permisos mínimos, pins, matrices y artefactos; configurar required checks externamente | `.github/*`, Dockerfiles, locks, SBOM | ejecución PR verde; scans; provenance/artefacto ligado a SHA | Todos los required checks verdes y protegidos | Permisos repo, runner, branch rules, registry/attestation |
| B14 | MySQL/migrations/grants | DDL:2; ARCH:358-365; Seguridad:49,169 | Baseline SQL existe pero no migrations; exacta versión por ambiente no fijada; no runtime/migrator users ni grants; no test append-only | BLOCKED_INFO | Bloqueante / P0 | B04, B06, B11, BN-01 | Fijar versión soportada, crear migrations y roles separados, ensayar fresh/upgrade/grants | migrations, DB config, grants/runbook/tests | fresh/rollback-forward/upgrade; runtime DDL denied; audit UPDATE/DELETE denied | Matriz de permisos y migraciones PASS en MySQL objetivo | Versión/host/TLS/owners y capacidad de crear usuarios en no-prod |
| B15 | Readiness no implementado | ADR-011; API:255-256; PLAN:13-14; S0:133-154 | Contrato actual reutiliza `HealthResponse`; no rutas/código; checks documentados: DB/storage/outbox worker; sin heartbeat definido | PARTIAL | Bloqueante / P0 | B03, B10, B12, B14, BN-01 | Implementar live sin dependencias y ready sólo con dependencias documentadas; no añadir OTLP/Entra sin decisión | management OpenAPI, health contributors/tests | fault injection DB/storage/worker; 200/503 y recuperación; sin detalles sensibles | Contract test y recuperación PASS | Semántica/TTL del heartbeat y adapter storage disponible |
| BN-01 | Fundación moderna ausente | S0 estructura/DoD; filesystem | Todos los directorios/archivos modernos faltan; raíz es CI4 | READY_TO_FIX | Bloqueante / P0 | BN-02 | Crear estructura aprobada separada sin modificar legacy | `backend/`, `frontend/`, `openapi/`, `docker/`, `scripts/`, `.github/` | clone limpio, install/build/test/compose PASS | Fundación completa VERIFICADA | Ninguna para scaffold local; toolchain/CI para verificar |
| BN-02 | Fuentes de arquitectura no versionadas | Git status/ls-files | `docs/` y `database/` no pertenecen a HEAD | READY_TO_FIX | Bloqueante / P0 | — | Revisar y versionar fuentes aprobadas antes de basar CI/migrations en ellas | `docs/**`, `database/**` | nuevo clon contiene hashes aprobados; status limpio | Fuente de verdad disponible desde el SHA | Aprobación de contenido/commit por owner no documentado |
| BN-03 | Entorno de ejecución no reproduce el baseline | README implementación; S0 DoD; tool inventory | Sin PHP/Composer/Docker; Node 24.19.0 no es pin; MySQL local 26.7.0 no prueba MySQL objetivo | BLOCKED_INFO | Bloqueante / P0 | BN-01, B13 | Proveer runner/host soportado o Docker y ejecutar matriz de bootstrap | tool-versions, Compose, CI | bootstrap desde cero en runner limpio | Al menos CI y un entorno dev reproducen todo | Runner aprobado, acceso Docker/registry si aplica |
| BN-04 | Baseline físico y calidad legacy no disponibles | análisis DB:17,23,325,386; DDD:624,640-643 | Sólo PNG lógico y código; sin DDL real, grants, volúmenes, duplicados o fixtures anonimizados | BLOCKED_INFO | Importante / P1 | B05, B08, B14 | Extraer metadatos autorizados y perfilado anonimizado/read-only | `database/legacy-schema/`, manifiesto, reporte de calidad | checksums, consultas de duplicados/huérfanos, aprobación DBA/negocio | Riesgos IAM/ETL cuantificados antes de shadow/provisioning | DDL autorizado, acceso read-only, reglas de anonimización |
| BN-05 | Ownership nominal y aprobaciones ausentes | PLAN:223,270; Seguridad:42-54 | Se nombran roles de revisión, no personas/teams ni CODEOWNERS | BLOCKED_INFO | Importante / P1 | todos los bloqueos con decisión | Publicar RACI/CODEOWNERS y autoridad de aceptación | `CODEOWNERS`, RACI, branch rules | revisión por owners y readback de reglas | Cada gate tiene responsable y aprobador | Nombres/teams y permisos institucionales |

Ninguna fila está `VERIFIED`. `READY_TO_FIX` significa que la remediación técnica puede comenzar sin inventar infraestructura; no significa que el bloqueo esté cerrado.

## 6. Dependencias entre bloqueantes

```text
BN-02 fuentes versionadas
  -> BN-01 scaffold moderno
     -> B01 OpenAPI -> B15 readiness
     -> B03 Outbox -> B15 readiness
     -> B13 CI/CD
     -> B14 migrations/grants

BN-05 ownership + B02 estados + B05 política correo + B06 clasificación IDs
  -> B04 frontera de migraciones
     -> B14 migrations
     -> B03 Outbox

B07 topología + B11 secret store + B08 recursos Entra
  -> B09 spike de cliente OIDC
  -> evidencia de Entry Gate (sin implementar login)

B10 storage/AV + B03 worker + B14 DB
  -> B15 readiness

B11 secret store + B12 destino OTLP + B14 DB + B10 storage
  -> infraestructura representativa
  -> B13 gates de seguridad/deploy

BN-04 baseline legacy
  -> B05 resolución de colisiones
  -> plan de provisioning/reconciliación de Sprint 1
```

Regla de orden: **decisión/evidencia → contrato/schema → implementación → tests → required check → gate**. No se debe implementar una migración IAM mientras B02/B05/B06/B04 estén sin resolver.

## 7. Priorización P0/P1/P2

### P0 — impide reproducibilidad, seguridad o Sprint 1

1. BN-02: versionar fuentes aprobadas.
2. BN-05: asignar autoridad de decisión/revisión.
3. B02/B05: cerrar semántica IAM y política de correo con evidencia.
4. B07/B08/B11: topología, recursos Entra y secretos.
5. B04/B14: ownership y migraciones/grants.
6. BN-01/BN-03: scaffold y runner reproducible.
7. B01/B03/B15: contrato, Outbox y health.
8. B13: CI/CD/supply chain y required checks.

### P1 — Entry Gate Sprint 1

- B06: resolver incompatibilidades ULID antes de congelar migraciones.
- B09: ejecutar el spike de cliente OIDC sólo cuando existan los prerrequisitos.
- B10: cerrar adapter productivo/AV; el port y fake pueden avanzar antes.
- B12: telemetría local y destino/política productivos.
- BN-04: DDL/profile legacy y fixtures anonimizados para identidad/provisioning.

### P2 — importante, no autoriza omitirlo antes de producción

- Optimización de dashboards/SLO tras medición real.
- Attestation/firma cuando la plataforma institucional confirme soporte, manteniendo mientras tanto artefacto inmutable por SHA y SBOM.
- Runbooks ampliados de capacidad, HA y DR más allá del Entry Gate, sin postergar los smoke/restore mínimos exigidos por la documentación.

## 8. Plan de resolución por paquetes

### R0-01 — Congelar y versionar fuentes de verdad

- **Objetivo:** hacer que las fuentes inspeccionadas existan en un clon del SHA.
- **Bloqueante relacionado:** BN-02, BN-05.
- **Precondiciones:** aprobación del contenido actual por la autoridad que se designe; no incluye cambios al legacy.
- **Archivos afectados:** `docs/**`, `database/mysql/001_create_servicios_moderno.sql`, `CODEOWNERS` cuando exista owner.
- **Cambios requeridos:** revisar contradicciones conocidas, registrar hashes y versionar los archivos; no convertir recomendaciones en decisiones.
- **Validaciones:** nuevo clon contiene archivos; enlaces Markdown resuelven; status limpio.
- **Tests:** link check y verificación de hashes.
- **Comandos verificables:** `git ls-files docs database`; `git status --short`; `git fsck --no-reflogs`.
- **Criterio de aceptación:** fuentes presentes y revisadas en un commit identificado.
- **Rollback:** revertir únicamente el commit documental si la revisión lo rechaza; no borrar el workspace original.
- **Dependencias:** ninguna técnica; BN-05 para aprobación.
- **Estado posible:** READY_TO_FIX → VERIFIED.

### R0-02 — Decisiones IAM/identificadores y frontera de sprints

- **Objetivo:** eliminar contradicciones antes de crear migraciones.
- **Bloqueante relacionado:** B02, B04, B05, B06.
- **Precondiciones:** matriz funcional de estados, política institucional de correo y owner de decisión.
- **Archivos afectados:** IAM, API, análisis DB, ER, DDL de referencia, ADR-005 sólo si se solicita excepción, backlog de sprints.
- **Cambios requeridos:** aprobar enum/transiciones; resolver correo unique; clasificar ocho IDs; publicar ownership S0/S1/S2.
- **Validaciones:** búsqueda de términos divergentes; revisión arquitectura/datos/seguridad.
- **Tests:** tests de transición diseñados; fixtures de correo mutable/colisiones; schema diff.
- **Comandos verificables:** `rg -n 'ACTIVA|ACTIVO|SUSPENDIDA|DESHABILITADO|DESACTIVADA|ARCHIVADO|AUTO_INCREMENT|correo_normalizado' docs database`.
- **Criterio de aceptación:** una sola semántica y un manifest de tablas por sprint, aprobados.
- **Rollback:** revertir el commit de decisión antes de generar migraciones; después, sólo cambio expand/contract.
- **Dependencias:** BN-05, BN-04 para decisión basada en datos.
- **Estado posible:** BLOCKED_INFO → READY_TO_FIX → VERIFIED.

### R0-03 — Scaffold moderno reproducible

- **Objetivo:** crear la fundación separada del CI4 sin negocio.
- **Bloqueante relacionado:** BN-01, BN-03.
- **Precondiciones:** R0-01; runner capaz de ejecutar Docker o toolchain fijado.
- **Archivos afectados:** `backend/`, `frontend/`, `openapi/`, `docker/`, `scripts/`, `.github/`.
- **Cambios requeridos:** Laravel 13/PHP 8.3 o 8.4, Angular 22 standalone, lockfiles, tool-version files y configuración mínima.
- **Validaciones:** instalaciones desde lockfiles, builds, tests vacíos/smoke.
- **Tests:** bootstrap desde clon limpio en CI y en un entorno de desarrollo soportado.
- **Comandos verificables:** `composer validate --strict`; `composer install`; `npm ci`; `npm run build`; `docker compose config --quiet`.
- **Criterio de aceptación:** clone/install/build/test reproducible sin tocar `app/` legacy.
- **Rollback:** retirar sólo el scaffold nuevo mediante revert del commit; legacy permanece intacto.
- **Dependencias:** R0-01, disponibilidad de runner.
- **Estado posible:** READY_TO_FIX → VERIFIED.

### R0-04 — Contrato OpenAPI desplegable

- **Objetivo:** cerrar B01 sin publicar capacidades futuras.
- **Bloqueante relacionado:** B01, B15.
- **Precondiciones:** R0-01; B02 para enum `/me`; B07 para servers/origins no ficticios o uso explícito de variables de servidor.
- **Archivos afectados:** `openapi/api.yaml`, `management.yaml`, `fragments/planned/**`, `redocly.yaml`, generator config y cliente generado.
- **Cambios requeridos:** aplicar la sección 9; no implementar endpoints de negocio.
- **Validaciones:** lint strict, bundle, breaking diff, generación determinista y compile.
- **Tests:** contract tests health; schema RFC 9457; headers; `/me` sólo como contrato previo aprobado.
- **Comandos verificables:** `redocly lint`; `redocly bundle`; `oasdiff breaking`; `openapi-generator-cli generate`; `npm run build`; `git diff --exit-code`.
- **Criterio de aceptación:** cero errores/warnings, cliente compila sin patch y sólo contiene operaciones desplegables.
- **Rollback:** revertir contrato y cliente juntos; nunca dejar cliente y spec en commits separados.
- **Dependencias:** B02, B07, R0-03.
- **Estado posible:** PARTIAL → VERIFIED.

### R0-05 — Migraciones y permisos de base

- **Objetivo:** traducir el baseline a migrations owned sin doble ejecución.
- **Bloqueante relacionado:** B04, B06, B14.
- **Precondiciones:** R0-02; versión MySQL objetivo y capacidad de crear usuarios en ambiente efímero.
- **Archivos afectados:** migrations por módulo/framework, manifest de ownership, config DB y scripts de grants.
- **Cambios requeridos:** aplicar la frontera de la sección 10; runtime sin DDL; migrator separado; auditoría append-only.
- **Validaciones:** fresh en DB desechable, upgrade ensayado, grants efectivos.
- **Tests:** constraints/índices, ULID, runtime DDL denied, auditoría UPDATE/DELETE denied.
- **Comandos verificables:** en un contenedor de test aislado llamado explícitamente `servicios_moderno_test`, `php artisan migrate:fresh --env=testing --force`; después `php artisan test --testsuite=Integration --filter=DatabaseGrants`.
- **Criterio de aceptación:** migrations PASS desde cero y grants mínimos demostrados.
- **Rollback:** en entornos persistentes usar roll-forward; `migrate:fresh` queda prohibido fuera del contenedor de test desechable.
- **Dependencias:** R0-02, R0-03, B11, datos B14.
- **Estado posible:** BLOCKED_INFO → READY_TO_FIX → VERIFIED.

### R0-06 — Outbox fiable

- **Objetivo:** implementar ADR-008 sin broker adicional.
- **Bloqueante relacionado:** B03.
- **Precondiciones:** R0-05 y política documentada de retry/dead-letter/replay.
- **Archivos afectados:** Shared migration, ports/adapters, worker/commands, métricas y tests.
- **Cambios requeridos:** aplicar la sección 11; handlers idempotentes e inbox por consumidor.
- **Validaciones:** dos workers concurrentes, leases vencidos, crash points, retry/backoff, poison y replay.
- **Tests:** suite de integración sobre MySQL objetivo, nunca SQLite.
- **Comandos verificables:** `php artisan test --testsuite=Integration --filter=Outbox`; comando de worker en modo `--once` contra fixtures aislados; consulta de backlog/edad.
- **Criterio de aceptación:** cero doble efecto, recuperación tras lease y dead-letter observable.
- **Rollback:** detener workers; conservar filas; roll-forward de schema compatible; replay sólo mediante comando auditado.
- **Dependencias:** R0-05, B12 para métricas.
- **Estado posible:** READY_TO_FIX → VERIFIED.

### R0-07 — Shared técnico, storage, observabilidad y health

- **Objetivo:** completar fundación sin negocio y probar fallos.
- **Bloqueante relacionado:** B10, B12, B15.
- **Precondiciones:** R0-03, R0-05, R0-06; adapter local aislado; decisiones productivas pueden permanecer separadas, pero no cerrar el gate.
- **Archivos afectados:** Shared ports/adapters, health contributors, config, collector local, tests.
- **Cambios requeridos:** live sin dependencias; ready con DB/storage/outbox worker únicamente; correlación/redacción; storage fuera de public.
- **Validaciones:** fault injection, recuperación, PII/token redaction, collector indisponible según política aprobada.
- **Tests:** 200/503, timeouts, MIME/hash, cuarentena, trace/log correlation.
- **Comandos verificables:** contract tests HTTP y `docker compose stop` sólo sobre servicios del Compose local desechable para simular fallos, seguido de `docker compose start`; no afecta servicios externos.
- **Criterio de aceptación:** comportamiento y recuperación coinciden con contratos y no filtran detalles.
- **Rollback:** deshabilitar exporter/adapters por config segura; health live sigue operativo; no degradar controles de upload.
- **Dependencias:** B10/B12 información externa para cierre productivo.
- **Estado posible:** BLOCKED_INFO o PARTIAL → VERIFIED sólo con ambos planos probados.

### R0-08 — CI/CD y supply chain

- **Objetivo:** convertir todos los tests en required checks reproducibles.
- **Bloqueante relacionado:** B13, BN-03, BN-05.
- **Precondiciones:** R0-03 a R0-07; permisos GitHub y runner.
- **Archivos afectados:** `.github/workflows/**`, dependabot/config equivalente, CODEOWNERS, Dockerfiles, SBOM/provenance config.
- **Cambios requeridos:** lint/test/contract/security; actions por SHA; imágenes por digest; mínimos permisos; SAST/SCA/secrets/SBOM/image scan; artifacts por commit.
- **Validaciones:** PR sin secretos, fork sin secrets, checks bloqueantes, artefacto descargado coincide con SHA.
- **Tests:** composer/npm audit, Semgrep/PHPStan/Larastan, CodeQL en lenguajes soportados del repo, gitleaks, SBOM, image scan.
- **Comandos verificables:** equivalentes locales/CI de cada scanner y consulta de GitHub required checks.
- **Criterio de aceptación:** pipeline completo verde y branch protection impide bypass no aprobado.
- **Rollback:** revertir workflow defectuoso sin desactivar controles; usar versión previa pinneada y registrar excepción temporal aprobada.
- **Dependencias:** permisos externos y todos los paquetes técnicos.
- **Estado posible:** PARTIAL → VERIFIED.

### R0-09 — Prueba de topología sesión/XSRF/TLS

- **Objetivo:** cerrar B07 con evidencia representativa.
- **Bloqueante relacionado:** B07.
- **Precondiciones:** manifiesto de hosts/origins, TLS y callback; R0-03/R0-04.
- **Archivos afectados:** Apache/reverse proxy, Laravel session/Sanctum/CORS/trusted proxies, runtime config Angular, tests E2E.
- **Cambios requeridos:** configurar la topología aprobada, no una supuesta; mantener tokens fuera de Angular.
- **Validaciones:** cookie `Secure`, `HttpOnly`, `SameSite`; XSRF; CORS exacto; callback; redirects; proxy headers.
- **Tests:** navegador real positivo/negativo bajo TLS; origen no permitido; cookie ausente; token XSRF inválido.
- **Comandos verificables:** suite E2E y captura de headers redactada; scanner TLS sobre host no productivo autorizado.
- **Criterio de aceptación:** matriz aprobada PASS para la topología objetivo.
- **Rollback:** volver a configuración previa del ambiente de prueba; no introducir bearer/JWT como bypass.
- **Dependencias:** datos externos B07/B11.
- **Estado posible:** BLOCKED_INFO → VERIFIED.

### R0-10 — Dossier externo Entra y secretos

- **Objetivo:** acreditar disponibilidad sin implementar login.
- **Bloqueante relacionado:** B08, B11.
- **Precondiciones:** owner institucional y B07.
- **Archivos afectados:** manifiestos no secretos, runbook de rotación y evidencias; ningún secreto versionado.
- **Cambios requeridos:** registrar tenant/app/callback/credential reference/claims/test users/conectividad de forma redactada.
- **Validaciones:** metadata OIDC y TLS alcanzables desde backend de prueba; secret reference accesible al runtime autorizado.
- **Tests:** preflight no autenticante y rotación en entorno no productivo cuando esté autorizado.
- **Comandos verificables:** script de preflight que imprime sólo estado/issuer esperado redactado, nunca secretos/tokens.
- **Criterio de aceptación:** cada prerrequisito tiene evidencia vigente y owner.
- **Rollback:** revocar credencial de prueba y retirar acceso al runner; conservar sólo evidencia redactada.
- **Dependencias:** información institucional.
- **Estado posible:** BLOCKED_INFO → VERIFIED.

### R0-11 — Spike de cliente OIDC para Entry Gate

- **Objetivo:** seleccionar por evidencia, sin implementar Sprint 1.
- **Bloqueante relacionado:** B09.
- **Precondiciones:** R0-10 cerrado y criterios/candidatos aprobados.
- **Archivos afectados:** informe de spike, harness temporal y lockfile del spike; no rutas productivas.
- **Cambios requeridos:** medir soporte de Authorization Code, PKCE, state, nonce, issuer, audience, tenant, JWKS/key rotation, clock skew y consumo único del code.
- **Validaciones:** tabla PASS/FAIL/NO SOPORTADO con versión y enlace primario de cada candidato.
- **Tests:** todos los negativos de IAM:136-141, incluido replay.
- **Comandos verificables:** suite del harness contra tenant/app no productivos; resultados sanitizados.
- **Criterio de aceptación:** candidato aprobado formalmente; ningún ganador se infiere de este documento.
- **Rollback:** borrar credenciales/recursos temporales según runbook; conservar informe y lockfile como evidencia.
- **Dependencias:** B07, B08, B11.
- **Estado posible:** BLOCKED_INFO → VERIFIED.

### R0-12 — Baseline legacy y perfilado IAM

- **Objetivo:** resolver B05 con datos y no con suposiciones.
- **Bloqueante relacionado:** BN-04, B05.
- **Precondiciones:** acceso read-only autorizado, anonimización y owner DBA/negocio.
- **Archivos afectados:** `database/legacy-schema/`, manifest de extracción, reporte de calidad y fixtures anonimizados; no `app/` legacy.
- **Cambios requeridos:** capturar DDL/índices/grants y contar correos duplicados, nulos, aliases, cambios y correspondencias proveedor.
- **Validaciones:** checksum, fecha/origen, ausencia de secretos/PII en artefactos versionados.
- **Tests:** queries de duplicados/huérfanos y reconciliación sobre copia autorizada.
- **Comandos verificables:** `mysqldump --no-data` y consultas `information_schema` ejecutadas por DBA en origen autorizado; los resultados sensibles no se suben sin sanitizar.
- **Criterio de aceptación:** política B05 se decide con reporte firmado y fixtures seguros.
- **Rollback:** eliminar de staging conforme al runbook aprobado; no modificar ni ejecutar ETL sobre legacy.
- **Dependencias:** información/acceso externo y BN-05.
- **Estado posible:** BLOCKED_INFO → VERIFIED.

### R0-13 — Automatizar y ejecutar PRE_SPRINT_1_GATE

- **Objetivo:** emitir una decisión binaria vinculada al SHA.
- **Bloqueante relacionado:** todos.
- **Precondiciones:** paquetes previos, manifest de evidencia y required checks.
- **Archivos afectados:** `scripts/pre-sprint1-gate.*`, esquema de evidencia, workflow de release gate.
- **Cambios requeridos:** leer estados permitidos; fallar si cualquier requisito obligatorio no es `VERIFIED`; validar vigencia/SHA de evidencias.
- **Validaciones:** casos sintéticos GO/NO-GO y rechazo de evidencia ausente, vencida o de otro SHA.
- **Tests:** unitarios del evaluador y ejecución en CI.
- **Comandos verificables:** `scripts/pre-sprint1-gate` en el runner soportado; la primera línea debe ser exactamente `GO` o `NO-GO`.
- **Criterio de aceptación:** sólo devuelve GO cuando todos los obligatorios son VERIFIED.
- **Rollback:** volver a la versión previa del gate; nunca cambiar un requisito a opcional para obtener verde.
- **Dependencias:** todos los bloqueantes P0/P1 obligatorios.
- **Estado posible:** READY_TO_FIX al final → VERIFIED.

## 9. Cambios OpenAPI

Estado actual: PARTIAL y fallido. Problemas confirmados:

1. `servers[0].url` termina en `/api/v1`; por composición, los health actuales son `/api/v1/health/*`, mientras PLAN/API REST los definen `/health/*`.
2. Hay 18 operaciones sin `summary`; faltan license, descripciones de tags y cinco respuestas 4xx según Redocly recommended.
3. El contrato incluye endpoints de Residencies, LaboratoryRequests e Inventories no implementados y expresamente fuera de Sprint 0.
4. `ResourceEnvelope` y `ResourcePage` permiten objetos arbitrarios; no son contratos fuertes.
5. `CurrentIdentityEnvelope.status` es string libre y omite `scopes` y `sessionExpiresAt` definidos en API REST.
6. No aparecen `ETag` ni `If-Match` aunque API REST los exige en mutaciones sensibles.
7. `X-Correlation-ID` sólo está modelado en la respuesta genérica de error, no de forma consistente.
8. No existen configuración Redocly strict, bundle, breaking diff ni generación reproducible.

Resolución propuesta, aún no implementada:

- `openapi/management.yaml`: servidor raíz; únicamente `/health/live` y `/health/ready`; no genera cliente Angular.
- `openapi/api.yaml`: servidor `/api/v1`; contrato realmente desplegable. Antes de Sprint 1 incluye `/me` aprobado y fuertemente tipado, pero no implica implementación OIDC.
- `openapi/fragments/planned/`: diseños futuros excluidos del bundle y del cliente; no se usan como evidencia de disponibilidad.
- `openapi/components/`: Problem Details RFC 9457, ULID, paginación, correlation, ETag/If-Match, errores comunes y schemas concretos.
- `redocly.yaml`: ruleset strict, sin ignore file como sustituto de corrección.
- Generación TypeScript Angular desde `api.yaml` fijada por versión/digest; output determinista y no editable.
- Breaking diff contra el último contrato desplegado identificable por tag/SHA, no simplemente contra una rama mutable.

Extensiones para slices posteriores permanecen en planned hasta el inicio del slice: schemas discriminados de solicitudes/inventarios, operaciones IAM administrativas, uploads y contratos de negocio. No se promueven en Sprint 0.

## 10. Cambios de base de datos/migraciones

### Frontera propuesta para eliminar el solapamiento

| Sprint | Ownership de migraciones | No pertenece aquí |
|---|---|---|
| Sprint 0 | Framework técnico (`sessions`, `cache`/locks, `jobs`/batches/failures si los drivers DB aprobados se mantienen); Shared técnico (archivos metadata, Outbox, inbox/idempotencia, auditoría, retención); tablas operativas de migración (`migracion_mapa_ids`, ejecuciones, checkpoints, rechazos, reconciliaciones) | Identidades, cuentas externas, provisioning, roles/permisos, módulos de negocio |
| Sprint 1 | `iam_identidades`, `iam_cuentas_externas` y persistencia estrictamente necesaria para provisioning OIDC, una vez cerrados B02/B05 | Roles/permisos/policies de negocio; tablas Organization |
| Sprint 2 | `iam_roles`, `iam_permisos`, asignaciones rol/permiso y Organization según PLAN | Residencies, LaboratoryRequests, Inventories |

Esta frontera es una resolución propuesta del conflicto PLAN:16/26; debe aprobarse y versionarse antes de generar migraciones. `sessions` y `cache` no se repiten en Sprint 1 si fueron creadas en Sprint 0.

Reglas:

- El baseline `001_create_servicios_moderno.sql` es referencia de aceptación; no se ejecuta además de migrations Laravel.
- Cada tabla tiene un único módulo/provider owner y un único sprint de creación.
- Runtime y migrator son principals distintos; runtime carece de DDL y de UPDATE/DELETE sobre auditoría.
- Fresh sólo se ejecuta en base efímera declarada; ambientes persistentes usan migrations expand/roll-forward.
- No se modifica el legacy ni se ejecuta ETL productivo en estos paquetes.

### Clasificación de `AUTO_INCREMENT`

| Tabla/columna | Clasificación solicitada | Disposición |
|---|---|---|
| `compartido_registros_auditoria.id` | Secuencia técnica de registro append-only | **REQUIERE DECISIÓN**. Mantenerla contradice la literalidad de ADR-005 y exige ADR CHANGE REQUIRED; cambiar a ULID cumple ADR. No decidir por inferencia. |
| `migracion_mapa_ids.id` | Legacy mapping / surrogate técnico | **REQUIERE DECISIÓN** entre ULID o clave compuesta existente. Mantener requiere excepción ADR explícita. |
| `organizacion_niveles_academicos.id` | Entidad catálogo | **CAMBIAR** a ULID bajo ADR-005, salvo ADR CHANGE REQUIRED aprobado. |
| `residencias_modalidades.id` | Entidad catálogo | **CAMBIAR** a ULID bajo ADR-005. |
| `residencias_sectores.id` | Entidad catálogo | **CAMBIAR** a ULID bajo ADR-005. |
| `residencias_ramos.id` | Entidad catálogo | **CAMBIAR** a ULID bajo ADR-005. |
| `planificacion_tipos_dia_inhabil.id` | Entidad catálogo | **CAMBIAR** a ULID bajo ADR-005. |
| `inventario_detalles_corte_mensual.id` | Detalle/snapshot, con unique `(id_corte,id_articulo)` | **REQUIERE DECISIÓN** entre ULID y clave compuesta; mantener autoincrement exige ADR CHANGE REQUIRED. |

No se realizará conversión masiva hasta aprobar los tres casos “requiere decisión”.

**ADR CHANGE REQUIRED**, únicamente si se pretende conservar algún `AUTO_INCREMENT`:

- **ADR afectado:** ADR-005.
- **Motivo:** su decisión vigente ordena ULID canónico para identificadores nuevos y no documenta excepciones para secuencias técnicas, catálogos ni detalles.
- **Impacto:** storage, migrations, mappers, OpenAPI si el ID se expone, ETL y pruebas de arquitectura/datos.
- **Alternativas:** (a) cambiar todos los identificadores nuevos a ULID y conservar ADR-005; (b) usar una clave compuesta donde ya existe identidad natural suficiente; (c) aprobar una excepción explícita y acotada por tabla. Este plan no selecciona (b) o (c) sin owner y evidencia.

## 11. Cambios Outbox

| Capacidad/campo | Evidencia actual | Estado requerido |
|---|---|---|
| `state` / `estado` | Ausente | Estado explícito y transiciones documentadas |
| `available_at` / `disponible_en` | Ausente | Elegibilidad para retry/backoff |
| `locked_until` / `bloqueado_hasta` | Ausente | Lease recuperable |
| `locked_by` / `bloqueado_por` | Ausente | Ownership diagnóstico del claim |
| `attempts` / `intentos` | Presente | Incremento atómico probado |
| `published_at` / `publicado_en` | Presente | Sólo tras efecto/handler confirmado según diseño |
| `failed_at` / `fallido_en` | Ausente | Marca terminal/dead-letter |
| `last_error` / `ultimo_error` | Presente | Redactado y acotado |
| Inbox por consumidor/evento | Ausente; la tabla de idempotencia HTTP no prueba inbox | Unique consumer+event y transacción con efecto |
| Dead-letter | Ausente | Estado terminal o tabla explícita; decisión documentada |
| Replay | Ausente | Comando autorizado, auditado e idempotente |

El paquete R0-06 debe implementar claim concurrente con una estrategia compatible con MySQL objetivo, lease, recuperación por expiración, retry/backoff acotado, poison message, inbox y replay. La estrategia concreta de dead-letter y los umbrales no están aprobados en las fuentes; deben registrarse antes de la migration. Usar `FOR UPDATE SKIP LOCKED` es la propuesta de `SPRINT_0_ENTREGABLE.md`, no evidencia de implementación.

Pruebas mínimas: dos workers sobre el mismo lote; crash antes y después del efecto; lease vencido; handler duplicado; backoff temporal; mensaje siempre fallido; replay autorizado; redacción de error; métrica de edad, intentos y fallos.

## 12. Cierre técnico de IAM previo a OIDC

No se implementa login. Este cierre se limita a coherencia de contrato/schema, prerrequisitos y spike planificado.

### Matriz de estados

| Estado fuente | Significado respaldado | Uso actual | Uso objetivo documentado | Transiciones respaldadas | Evidencia |
|---|---|---|---|---|---|
| `PENDIENTE` (IAM y DDL) | Perfil no inferible/provisioning incompleto; no debe crear sesión autorizada | No implementado en moderno | Espera resolución administrativa | Primer login puede dejarla PENDIENTE; salida no documentada | IAM:72,91,96 |
| `ACTIVA` (IAM) | Identidad habilitada; es condición de autorización | No implementado | Permite sesión si los demás controles pasan | Origen/destino no documentados | IAM:91,126 |
| `SUSPENDIDA` (IAM) | No obtiene sesión | No implementado | Bloqueo de login | Reversibilidad y relación con “deshabilitar” no documentadas | IAM:91,139 |
| `DESACTIVADA` (IAM) | Sólo aparece en la lista | No implementado | No definido con precisión | No documentadas | IAM:91 |
| `ACTIVO` (DDL) | Default y estado permitido | Sólo baseline de referencia | Contradice género/vocabulario IAM | No documentadas | DDL:158,166 |
| `DESHABILITADO` (DDL) | Estado permitido; existe `deshabilitado_en` | Sólo baseline de referencia | Podría corresponder a suspensión o desactivación, pero no puede inferirse cuál | No documentadas | DDL:162,166; IAM:72,99 |
| `ARCHIVADO` (DDL) | Estado permitido; referencias históricas sobreviven al archivo | Sólo baseline de referencia | Ciclo de vida no definido | No documentadas | DDL:166; ER:749 |
| Legacy | No existe un enum IAM objetivo verificable | Flujos encontrados buscan por correo y, en otra variante, por un `id` del proveedor; almacenan access token en sesión CI4 | Sólo fuente de comportamiento a migrar, no modelo objetivo | No aplicable | OAuth controllers/models inspeccionados |

**ESTADO: BLOQUEADO POR INFORMACIÓN FALTANTE.** Se necesita una decisión funcional que defina significado, capacidad de login, actor autorizado, transición, reversibilidad, timestamps, revocación de sesiones y tratamiento histórico. No se adopta la recomendación del entregable anterior como hecho.

### Identidad por correo

- Decisión vigente: identidad externa por `(tid,oid)`; correo/nombre son presentación y pueden cambiar.
- DDL vigente de referencia: unique en `(proveedor,id_inquilino,sujeto_proveedor)` y también unique global nullable en `correo_normalizado`.
- Documento de análisis: la unicidad de correo está “condicionada a política institucional”. Esa política no aparece.
- Legacy: existen búsquedas y altas por correo; esto demuestra riesgo de migración, no legitimidad del correo como identidad nueva.

Antes de modificar schema se requiere: política de aliases/cuentas compartidas/reciclaje de correo; perfil de duplicados/nulos/cambios; regla de merge/split; conducta ante colisión durante provisioning; reconciliación por `(tid,oid)`.

### Cliente OIDC

No hay librería seleccionada para Laravel nuevo. La presencia de `league/oauth2-client` en el legacy no la convierte en decisión. R0-11 debe evaluar, sin ganador predeterminado:

- Authorization Code backend confidential client;
- PKCE, state y nonce, generación/almacenamiento/consumo único;
- issuer, audience y tenant allowlist;
- firma/JWKS, cache y key rotation;
- `exp`, `nbf`, `iat` y clock skew acotado;
- code replay/consumo único y errores cerrados;
- mantenimiento, compatibilidad con Laravel 13/PHP elegido y posibilidad de test determinista.

## 13. Infraestructura pendiente

| Área | Documentado | Implementado/verificado | Estado |
|---|---|---|---|
| Local Compose | Propuesta PHP/Apache/MySQL/Node/Mailpit/OTel | No existe Compose; Docker ausente en host | READY_TO_FIX en runner adecuado |
| Topología web | Apache/reverse proxy y same-site | Origins, hosts, DNS, TLS, proxy/WAF desconocidos | BLOCKED_INFO |
| Entra no productivo | Requerido por ambiente | Sin evidencia | BLOCKED_INFO |
| Secret store | Requerido | Tecnología/ACL/rotación desconocidas | BLOCKED_INFO |
| Storage/AV | Port y controles | Adapter/AV/capacidad/backup desconocidos | BLOCKED_INFO |
| OTLP | Collector/backend requeridos | Local y productivo ausentes; destino/protocolo no definidos | BLOCKED_INFO |
| MySQL | 8.0.16+ en DDL; MySQL 8 obligatorio | Versión por ambiente, TLS, runtime/migrator y grants desconocidos | BLOCKED_INFO |
| GitHub | Environments/reviews/required checks diseñados | Permisos, runner, branch rules y registry desconocidos | BLOCKED_INFO |

Puede continuar sin información externa: versionado de documentos aprobado, scaffold, contrato sin servers ficticios, ports/fakes, migrations Shared no controvertidas, unit tests y definición de workflows. No puede declararse cierre productivo ni GO.

## 14. Seguridad y supply chain

| Control | Existencia real | Acción de cierre |
|---|---|---|
| Workflows | No `.github/` | Crear cuatro workflows o composición equivalente |
| Actions pin por SHA | No aplica todavía; no actions | Pin completo y actualización controlada |
| Imágenes por digest | No Dockerfiles/Compose | Pin de imágenes base y servicios; registrar digest |
| `composer audit` | No backend Composer | Añadir y ejecutar sobre lockfile moderno |
| `npm audit` | No frontend package/lock | Añadir política y ejecutar sobre `npm ci` |
| Semgrep/SAST PHP | Herramienta y workflow ausentes | Ruleset versionado + Larastan/PHPStan |
| CodeQL | Ausente | Aplicar a lenguajes soportados presentes; no usarlo como falsa cobertura PHP |
| gitleaks | Ausente | Escanear diff/historial pertinente sin exponer hallazgos secretos |
| SBOM | Ausente | Generar CycloneDX/SPDX por backend, frontend e imagen |
| Image scan | Ausente | Escanear imagen construida por digest |
| Artifact por SHA | Ausente | Build once, checksum/provenance y promotion sin recompilar |
| Dependency review | Ausente | Bloquear vulnerabilidades según política documentada |
| Permisos mínimos | Sin workflows | `permissions` explícitos; ningún secreto en PR de forks |
| Branch protection | No verificable localmente | Evidencia vía configuración GitHub/API autorizada |

El cierre requiere resultados ligados al mismo commit que se pretende promover. Un workflow escrito pero nunca ejecutado permanece IMPLEMENTADO, no VERIFICADO.

## 15. Tests y evidencia requerida

| Bloqueante | Evidencia objetiva mínima de cierre |
|---|---|
| B01 | Redocly strict 0/0; bundle; breaking diff; cliente generado compila; `git diff --exit-code`; contract tests PASS |
| B02 | Documento aprobado + tests exhaustivos de transiciones y deny login por estado |
| B03 | Integración MySQL concurrente, lease/crash/retry/poison/inbox/replay PASS |
| B04 | Manifest de ownership + `migrate:fresh` efímero + upgrade sin duplicados |
| B05 | Reporte de perfilado + fixtures correo mutable/colisión + lookup sólo por identidad externa PASS |
| B06 | Búsqueda sin `AUTO_INCREMENT` incompatible o ADR exception aprobada por cada caso |
| B07 | E2E navegador TLS/XSRF/cookie/CORS/callback en topología representativa |
| B08 | Manifiesto no secreto y preflight de tenant/app/callback/claims/test users |
| B09 | Matriz del spike y suite negativa PASS; aprobación del candidato |
| B10 | Storage privado + EICAR/control AV + cuarentena/promoción/restore PASS |
| B11 | Secret scan limpio y rotación/revocación ensayada |
| B12 | Trace/log/metric correlados, redacción PII y fallo collector según política |
| B13 | Required checks verdes, pins/digests, audits, SAST/SCA, SBOM, image scan, artifact checksum |
| B14 | Fresh/upgrade/grants; runtime DDL denied; auditoría UPDATE/DELETE denied |
| B15 | live independiente; ready 200/503/recuperación para DB/storage/worker; sin detalles sensibles |
| BN-01 | Clone → install → build → test → compose desde cero |
| BN-02 | Archivos en `git ls-files` y clon limpio con hashes aprobados |
| BN-03 | Pipeline y entorno dev soportado reproducen el mismo resultado |
| BN-04 | DDL/grants/profile legacy con checksums y fixtures anonimizados |
| BN-05 | CODEOWNERS/RACI y readback de branch rules/reviews |

Toda evidencia debe incluir: SHA, fecha, herramienta/versión, comando, exit code, ambiente y vínculo al artifact/log. Capturas manuales sin contexto no bastan.

## 16. Información faltante

Para cada bloqueo interno/funcional:

| Bloqueante | Información exacta requerida | Por qué / decisión bloqueada | Dónde se buscó | Archivo esperado | Quién debe proporcionarla | Qué puede continuar |
|---|---|---|---|---|---|---|
| B02 | Definición y transición de cada estado, login permitido, reversibilidad, revocación y archivo | Enum de dominio/schema/OpenAPI | IAM, DDL, ER, análisis DB/DDD, legacy | `docs/implementacion/decisiones/IAM_ESTADOS.md` o ADR aclaratorio | Owner no documentado; PLAN exige owner negocio/seguridad | Scaffold, Shared, OpenAPI no-IAM |
| B05 | Política de unicidad/alias/reciclaje de correo y reporte de colisiones | Índice unique y provisioning | ADR-006, IAM, análisis DB, DDL, controllers/models legacy | decisión IAM + reporte de perfilado anonimizado | Owner no documentado; DBA/negocio aparecen como roles de aprobación de datos | `(tid,oid)` contract, fixtures sintéticos |
| B06 | Aceptación de clasificación y decisión para tres casos técnicos/detalle | Cumplimiento ADR-005 | ADR-005, DDL, ER | ADR change si se mantiene autoincrement; decision record si se cambia | Owner arquitectura no documentado | Cambios claros en catálogos, sin migrar datos |
| B04 | Aprobación de tabla→sprint→módulo | Evitar migraciones duplicadas | PLAN, ARCH, S0, DDL | `docs/implementacion/MIGRATION_OWNERSHIP.md` | Owner técnico/datos no documentado | Diseñar migrations Shared en branch sin merge |
| B03 | Umbral, fórmula de backoff, retención/replay y representación dead-letter | Schema/worker/test exactos | ADR-008, PLAN, S0, DDL | `docs/implementacion/OUTBOX_POLICY.md` | Owner técnico/operación no documentado | Interfaces/tests de concurrencia parametrizados |
| B15 | TTL/semántica de heartbeat del worker y timeout de checks | Readiness determinista | ADR-011, API, PLAN, S0 | health runbook/contract | Owner operación no documentado | live y contributors abstraídos |
| BN-05 | Personas/teams y autoridad de aceptación | Reviews y responsables reales | PLAN, Seguridad, repo | `CODEOWNERS` + RACI | La propia organización; no hay nombre documentado | Trabajo local sin declaración VERIFIED final |

Cada fila permanece **BLOQUEADO POR INFORMACIÓN FALTANTE** cuando el dato indicado sea condición de cierre. La ausencia no detiene los paquetes independientes señalados.

## 17. Información externa requerida

Los ejemplos siguientes muestran estructura, no valores ficticios. No deben incluir secretos.

| Dato requerido | Motivo | Bloqueante | Quién debe proporcionarlo | Formato esperado | Ejemplo estructural sin valores |
|---|---|---|---|---|---|
| Topología por ambiente | Cookies/XSRF/CORS/TLS/callback | B07 | Owner de operación no nombrado | YAML/diagrama aprobado | `environment: <name>`, `spa_origin: <https-origin>`, `api_origin: <https-origin>`, `callback_uri: <exact-uri>`, `tls_owner: <team>` |
| Tenant y App Registration no productivos | Spike/preflight OIDC | B08/B09 | Owner IAM no nombrado | Manifiesto redactado | `tenant_id: <reference>`, `client_id: <reference>`, `redirect_uris: [<exact-uri>]`, `credential_reference: <secret-store-ref>` |
| Credencial/certificado y lifecycle | Confidential client seguro | B08/B11 | Seguridad/IAM aparecen como roles; persona no documentada | Referencia, nunca valor | `type: <secret-or-certificate>`, `reference: <path>`, `expires_at: <date>`, `rotation_owner: <team>` |
| Claims y usuarios de prueba | Validar mapping/negativos | B08/B09 | Owner IAM/negocio no documentado | JSON redactado + catálogo de casos | `claims_present: [<claim-name>]`, `test_case: <case-id>`, `expected_access: <allow-or-deny>` |
| Secret store | APP_KEY/DB/Entra/OTLP | B11 | Owner seguridad/operación no nombrado | Decision record | `provider: <approved-provider>`, `auth_method: <method>`, `reference_pattern: <pattern>`, `rotation: <policy>` |
| Storage privado y antivirus | Upload/ready/backup | B10/B15 | Owner operación/seguridad no nombrado | Decision record + endpoint refs | `driver: <approved-driver>`, `capacity: <value-unit>`, `backup: <policy-ref>`, `scanner: <service-ref>`, `failure_policy: <policy>` |
| OTLP collector/backend | Telemetría productiva | B12 | Operación/SOC figuran como consumidores; owner no nombrado | Manifiesto no secreto | `protocol: <grpc-or-http>`, `endpoint_ref: <ref>`, `tls: <policy>`, `auth_ref: <ref>`, `retention: <policy>` |
| MySQL por ambiente y grants | Migrations/runtime/audit | B14 | DBA está documentado como rol revisor | Ficha de servicio + grants redactados | `version: <exact-version>`, `tls: <policy>`, `runtime_principal: <ref>`, `migrator_principal: <ref>`, `backup_restore_evidence: <artifact>` |
| GitHub permisos/runners/rules | Required checks y artifacts | B13/BN-03/BN-05 | Owner repositorio no documentado | Export/config readback | `runner: <label>`, `required_checks: [<name>]`, `environment_approvers: [<team>]`, `artifact_registry: <ref>` |
| DDL/profile legacy autorizado | Resolver colisiones y preparar shadow | BN-04/B05 | DBA y negocio son roles documentados | DDL sin datos + reportes anonimizados/checksums | `source: <environment-ref>`, `captured_at: <timestamp>`, `checksum: <sha256>`, `pii_handling: <policy-ref>` |
| Owners/RACI | Autoridad de decisión y operación | BN-05 | No existe ownership nominal documentado | Markdown/CODEOWNERS | `area: <area>`, `responsible: <team-or-person>`, `approver: <team-or-person>`, `escalation: <channel-ref>` |

No se solicita ninguna credencial, token, secreto o dato personal dentro del repositorio. Sólo referencias y evidencia redactada.

## 18. Checklist PRE_SPRINT_1_GATE

El gate evalúa requisitos obligatorios contra un manifest de evidencias ligado al commit. Algoritmo requerido:

1. validar que cada ID obligatorio B01–B15 y BN-01–BN-05 tenga estado permitido;
2. rechazar cualquier estado distinto de `VERIFIED` para un requisito obligatorio;
3. validar que evidencia, tests y required checks correspondan al mismo SHA y no estén vencidos;
4. imprimir como primera línea exclusivamente `GO` o `NO-GO`;
5. después de esa línea, listar IDs no verificados y evidencias inválidas.

Checklist de decisión actual:

- [ ] Fuentes y decisiones versionadas y aprobadas (BN-02/BN-05).
- [ ] Fundación moderna reproducible desde clon limpio (BN-01/BN-03).
- [ ] OpenAPI desplegable, strict, generado y sin breaking no versionado (B01).
- [ ] Estados, correo, ULID y ownership de migraciones resueltos (B02/B04/B05/B06).
- [ ] Outbox completo y probado concurrentemente (B03).
- [ ] Topología TLS/session/XSRF verificada (B07).
- [ ] Recursos Entra no productivos acreditados, sin iniciar login (B08).
- [ ] Cliente OIDC seleccionado mediante spike probado (B09).
- [ ] Storage/AV, secret store y OTLP cerrados (B10/B11/B12).
- [ ] CI/CD/supply chain requerido y verde (B13).
- [ ] Migrations/grants y auditoría append-only verificados (B14).
- [ ] Live/ready y recuperación verificados (B15).
- [ ] Baseline legacy/perfilado IAM disponible (BN-04).

El archivo operativo complementario es `PRE_SPRINT_1_CHECKLIST.md`. El script automatizado se crea en R0-13; no se creó ahora porque la instrucción vigente prohíbe modificar código antes de producir estos documentos.

## 19. Resultado actual GO/NO-GO

**NO-GO**

Motivos determinantes:

- cero bloqueantes obligatorios están `VERIFIED`;
- no existe el proyecto moderno ni un pipeline que pueda probarlo;
- OpenAPI falla su lint y no representa sólo capacidades desplegables;
- estados IAM, correo e IDs no están resueltos;
- Outbox, migrations, grants y health no están implementados;
- topología, Entra, secretos, storage/AV, OTLP y GitHub dependen de información externa ausente;
- las propias fuentes inspeccionadas no pertenecen al commit actual.

La condición exacta para cambiar a GO es que **todas** las filas obligatorias B01–B15 y BN-01–BN-05 tengan estado `VERIFIED`, con evidencia vigente del mismo commit, y que R0-13 produzca `GO`. `READY_TO_FIX`, `PARTIAL`, `OPEN` y `BLOCKED_INFO` nunca cuentan como cierre.

## 20. Secuencia exacta recomendada

1. Designar owners y autoridad de aprobación (BN-05).
2. Revisar y versionar las fuentes actuales (R0-01/BN-02).
3. Obtener en paralelo la información externa de topología, Entra, secret store, storage/AV, OTLP, MySQL, GitHub y legacy; no esperar para continuar lo local.
4. Cerrar la decisión funcional de estados/correo y la clasificación ULID (R0-02).
5. Aprobar el manifest tabla→módulo→sprint (B04).
6. Crear el scaffold moderno y runner reproducible (R0-03).
7. Corregir/split OpenAPI, aprobar `/me` y activar generación/diff (R0-04).
8. Crear migrations Shared/framework y comprobar grants en MySQL objetivo (R0-05).
9. Implementar y verificar Outbox/inbox/dead-letter/replay (R0-06).
10. Implementar Shared técnico, collector local y health; ejecutar fault injection (R0-07).
11. Activar CI/CD y supply chain como required checks (R0-08).
12. Con datos externos disponibles, probar topología (R0-09), acreditar Entra/secretos (R0-10) y perfilar legacy (R0-12).
13. Ejecutar el spike OIDC de Entry Gate sin código productivo de login (R0-11).
14. Ejecutar todas las suites en el mismo SHA, adjuntar evidencias y resolver cualquier fallo.
15. Automatizar/ejecutar `PRE_SPRINT_1_GATE` (R0-13).
16. Sólo si el resultado es `GO`, autorizar el inicio de Sprint 1. Hasta entonces se conserva **NO-GO**.
