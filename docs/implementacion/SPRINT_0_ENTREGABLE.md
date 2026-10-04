# Sprint 0 — Fundación técnica ejecutable

Fecha de revisión: 2026-09-06  
Estado propuesto: **GO condicionado para Sprint 0; NO-GO para Sprint 1 hasta cerrar sus criterios de entrada**  
Alcance de este documento: revisión de consistencia y diseño ejecutable. No contiene lógica de Residencies, Inventories ni LaboratoryRequests.

## 1. Dictamen ejecutivo

Las decisiones arquitectónicas principales son coherentes entre sí: monolito modular, DDD, CQRS ligero, Strangler, OpenAPI First, ULID, Entra OIDC con cliente confidencial, Sanctum por cookie, RBAC más Policies, Outbox, storage privado, auditoría append-only y OpenTelemetry. No se propone sustituir ninguna.

La documentación **no está todavía en estado ejecutable desde cero**. El contrato OpenAPI actual falla el lint recomendado con 18 errores y 14 advertencias; la ruta efectiva de health contradice el plan; el DDL de Outbox no permite implementar de forma segura claim, retry y dead-letter; los estados IAM no coinciden entre diseño y DDL; y Sprint 0/Sprint 1 se solapan en migraciones IAM. Estas diferencias deben resolverse como trabajo de Sprint 0 y no transferirse como deuda a Sprint 1.

El repositorio actual es CodeIgniter 4. La aplicación moderna debe vivir en un repositorio dedicado cuya raíz sea `servicios.moderno/`; no debe anidarse dentro de `app/` ni compartir `composer.json`, `public/`, `writable/` o pipeline con el legacy. Esta separación aplica literalmente `ARQUITECTURA_LARAVEL.md:5`.

## 2. Auditoría de consistencia documental

### 2.1 Contradicciones

| Severidad | Hallazgo y evidencia | Resolución obligatoria |
|---|---|---|
| **Bloqueante** | `PLAN_EJECUCION.md:14` promete `/health/live` y `/health/ready`, pero `openapi.yaml:9-10,24,36` define un servidor terminado en `/api/v1`; por tanto las rutas contractuales reales son `/api/v1/health/*`. | Health operacional será **no versionado** en `/health/*`. Crear `openapi/management.yaml` con servidor raíz y dejar `openapi/api.yaml` bajo `/api/v1`. El cliente Angular se genera sólo desde `api.yaml`. |
| **Bloqueante para Sprint 1** | `IAM_ENTRA_ID.md:91` define `PENDIENTE`, `ACTIVA`, `SUSPENDIDA`, `DESACTIVADA`; el DDL `001_create_servicios_moderno.sql:158,166` usa `ACTIVO`, `DESHABILITADO`, `ARCHIVADO`. | Adoptar un único enum canónico antes de migrar. Recomendación: `PENDIENTE`, `ACTIVA`, `SUSPENDIDA`, `DESACTIVADA`; eliminar género inconsistente y documentar transición/semántica. |
| **Bloqueante para DoD** | ADR-008 exige claim seguro, retry exponencial y dead-letter (`ADR-008:21`), pero `compartido_mensajes_salida` sólo tiene `publicado_en`, `intentos` y `ultimo_error` (`DDL:41-55`). No hay `disponible_en`, lease/owner, estado ni dead-letter. | Extender la migración Sprint 0 con `estado`, `disponible_en`, `bloqueado_hasta`, `bloqueado_por`, `fallido_en` y `motivo_fallo`; índice `(estado, disponible_en, ocurrido_en)`. Claim con `FOR UPDATE SKIP LOCKED` dentro de transacción y lease recuperable. |
| **Importante** | Sprint 0 crea “IAM base” (`PLAN_EJECUCION.md:16`), mientras Sprint 1 vuelve a asignar `iam_identidades`, `iam_cuentas_externas`, session/cache (`:26`). | Sprint 0 crea sólo tablas técnicas Shared, migración operativa, `sessions`, `cache`, `jobs` y esquema mínimo IAM necesario para compilar el autenticable, sin provisioning. Sprint 1 agrega/activa tablas y comportamiento OIDC. Registrar la división en el backlog. |
| **Importante** | README permite PHP 8.3/8.4 y MySQL 8.0.16+ (`README:28-30`), pero Sprint 0 exige minor exacta (`PLAN:269`). A la fecha MySQL 8.0 está fuera de su ciclo normal y 8.4 es la línea LTS. | Pin único: PHP **8.4.x**, MySQL **8.4.x LTS**, Node **22.22.3**, Angular **22.0.x**. Fijar patch/digest en `versions.env` y Renovate; desarrollo, CI y producción consumen el mismo archivo. |
| **Importante** | `ARQUITECTURA_LARAVEL.md:154,185,280` declara `FileStorage` como port de varios consumidores y `:310` vuelve a declararlo en Shared. | La interfaz técnica única vive en `Shared/Application/Ports/FileStorage`; cada módulo puede declarar un port semántico propio sólo si su contrato difiere. No duplicar interfaces idénticas. |
| **Importante** | El contrato actual publica operaciones futuras de negocio (`openapi.yaml:109-320`) aunque Sprint 0 declara cero casos productivos (`PLAN:13`) y las restricciones prohíben implementarlos. | Separar `openapi/fragments/planned/` del bundle desplegable. Sprint 0 publica sólo management y capacidades realmente implementadas. Una operación entra a `api.yaml` cuando inicia su slice, antes del código. |
| **Importante** | ADR-005 ordena ULID para identificadores nuevos (`ADR-005:21`), pero el DDL introduce `AUTO_INCREMENT` en auditoría, mapa de migración, catálogos y detalle de cierre (`DDL:71,136,290,319,397,405,873,1168`). | Toda identidad de entidad nueva o expuesta usa ULID. Tablas puente/detalle pueden usar PK compuesta sin surrogate; si un contador técnico necesitara BIGINT, debe documentarse como secuencia y no como identidad/API. Sprint 0 corrige auditoría y tablas operativas antes de crear migraciones. |
| **Menor** | ADR-002 y ADR-003 aún hablan de excepciones o de “recomendado”, mientras las decisiones de ejecución ya fijan Laravel 13 y Angular 22. | Mantener el razonamiento histórico del ADR y actualizar README/plan a “obligatorio” sin reescribir el resultado aceptado. |

### 2.2 Omisiones

| Severidad | Omisión | Cierre de Sprint 0 |
|---|---|---|
| **Bloqueante** | No existe repositorio moderno ni bootstrap separado; el árbol inspeccionado sigue siendo CI4. | Crear repo `servicios.moderno`, branch protection, CODEOWNERS y environments antes de considerar cumplida la fundación. |
| **Bloqueante** | No hay definición de qué comprueba readiness ni límites de tiempo. | Liveness sólo comprueba proceso. Readiness comprueba MySQL nueva, migrations al día, storage privado escribible mediante probe no persistente y worker heartbeat con timeout de 2 s por check; nunca consulta Entra ni legacy. Respuesta pública mínima, detalle sólo en telemetría. |
| **Bloqueante** | Outbox no define orden, lease, poison messages, idempotencia de consumidores ni conservación. | Semántica at-least-once, orden sólo por aggregate/version, `event_id` único en inbox, 10 intentos, backoff con jitter, dead-letter explícito y replay manual auditado. |
| **Importante** | No se define namespace de contratos publicados entre módulos. | Añadir `Application/Published/{DTO,Events}` por proveedor. Sólo esa superficie, ports del consumidor y Shared pueden cruzar fronteras. |
| **Importante** | No hay estrategia verificable para evitar `Authorization: Bearer` aunque Sanctum puede aceptar tokens. | No publicar migración `personal_access_tokens`, no usar `HasApiTokens`, rechazar `Authorization` en rutas first-party y autenticar exclusivamente sesión/cookie. |
| **Importante** | No se especifica el paquete OIDC ni criterios de selección. | No instalar uno a ciegas en Sprint 0. Sprint 1 exige spike contra tenant de prueba y evidencia de validación `state`, `nonce`, PKCE, JWKS, `iss`, `aud`, `tid`, tiempos y consumo único del code. |
| **Importante** | `iam_identidades.correo_normalizado` es `UNIQUE` (`DDL:164`) aunque IAM declara `(tid,oid)` como identidad y el correo mutable sólo como presentación (`IAM:68,83`). | Quitar unicidad global del correo o convertirla en una regla de negocio explícita con proceso de colisión. Nunca fusionar ni rechazar una identidad sólo por correo coincidente. |
| **Importante** | No se define la estrategia exacta de migraciones respecto al DDL baseline. | Las migraciones Laravel son ejecutables y autoritativas. El SQL completo queda como referencia de aceptación; CI compara esquema normalizado y prohíbe ejecutarlos ambos. |
| **Importante** | Auditoría append-only no tiene enforcement verificable. | Usuario runtime recibe `INSERT, SELECT` y nunca `UPDATE/DELETE` sobre auditoría; usuario migrator separado. Test de grants en entorno de integración. |
| **Importante** | No se define cómo se genera y verifica SBOM de dos ecosistemas. | Generar CycloneDX JSON para Composer y npm, más SPDX del artefacto/imagen; conservar por SHA y publicar como artifact, no dentro del bundle. |
| **Importante** | No hay política de pin para GitHub Actions e imágenes. | Actions por SHA completo, imágenes por tag de patch + digest, Dependabot/Renovate con revisión humana. |
| **Menor** | No hay locale, zona de visualización ni contrato de runtime config Angular. | Runtime config versionado por schema, `apiBaseUrl` same-origin, locale `es-MX`; UTC sólo en persistencia/API. |

### 2.3 Ambigüedades

| Severidad | Ambigüedad | Decisión de implementación |
|---|---|---|
| **Bloqueante para Sprint 1** | “Same-site” permite subdominios diferentes, pero el XSRF automático de Angular es más seguro y predecible con URLs same-origin. | Servir SPA y API bajo el **mismo origin** detrás de Apache. Si operación exige subdominios, realizar prueba específica de cookie/XSRF/CORS antes de Sprint 1; no improvisar un token en storage. |
| **Importante** | “Provider y ocho módulos vacíos sólo donde haya código” (`PLAN:286`) choca con “no crear carpeta vacía” (`ARQUITECTURA:27`). | En Sprint 0 sólo existe `Shared`; `config/modules.php` declara módulos futuros deshabilitados sin carpetas. IAM aparece al comenzar Sprint 1. |
| **Importante** | Auditoría aparece relacionada con Outbox, pero el flujo de comando la escribe en la transacción (`ARQUITECTURA:346-352`). | La evidencia crítica se inserta síncronamente en la misma transacción. Outbox puede disparar proyecciones, nunca ser la única fuente del audit record. |
| **Importante** | “OpenTelemetry” no define auto-instrumentación, protocolo ni comportamiento si el collector falla. | OTLP/HTTP protobuf a collector local, export batch, timeout corto y fail-open para telemetría; la operación de negocio no falla por indisponibilidad del collector. Logs JSON siguen disponibles. |
| **Menor** | Apache/Nginx se deja como opción en la petición, pero los documentos aprobados eligen Apache. | Usar Apache 2.4.68+ en local y objetivo, con PHP-FPM separado. Nginx queda fuera de Sprint 0. |

### 2.4 Resultado de validación de `openapi.yaml`

Ejecutado: `@redocly/cli 2.14.3`, ruleset recomendado. Resultado: **exit 1, 18 errores y 14 advertencias**.

- 18 operaciones carecen de `summary`.
- falta `info.license` y descripción en los ocho tags;
- cinco operaciones carecen de respuesta 4xx según el ruleset;
- los envelopes `Resource`/`ResourcePage` son genéricos (`object` con `additionalProperties`) y destruyen el tipado del cliente Angular;
- no se declaran en todas las operaciones `X-Correlation-ID`, `401`, `403`, `429`, `500` y `503` aplicables;
- no existe `If-Match`/`ETag` pese a ser convención obligatoria;
- `PageAfter` no debe aceptar `null` explícito: la ausencia del parámetro ya expresa que no hay cursor;
- el header XSRF requerido como argumento produce métodos generados que obligan a la UI a pasar el secreto manualmente; debe inyectarlo el interceptor y documentarse con `x-csrf-protected: true`;
- `CurrentIdentityEnvelope` omite `scopes` y `sessionExpiresAt` definidos en `API_REST.md:112`;
- las uniones discriminadas de solicitudes e inventario no modelan exclusividad; hoy son objetos libres;
- faltan ejemplos, constraints de fechas cruzadas, formatos de permisos/códigos y `additionalProperties: false` en varios objetos;
- el `server` de ejemplo no puede llegar a `/auth/*` ni a health no versionado.

## 3. Registro de riesgos

| ID | Riesgo | Prob./impacto | Tratamiento y evidencia de cierre |
|---|---|---|---|
| R0-01 | Crear el moderno dentro del legacy y mezclar dependencias/deploy. | Alta/Crítico | Repo separado; CI comprueba que no existan rutas CI4 en artefactos modernos. |
| R0-02 | Contrato “verde” pero cliente generado sin tipos útiles. | Alta/Alto | schemas por operación, compile test del cliente y diff de árbol limpio. |
| R0-03 | Eventos duplicados, perdidos o bloqueados indefinidamente. | Alta/Alto | pruebas de crash antes/después del efecto, lease recuperable, inbox y dead-letter. |
| R0-04 | Sesión funciona localmente pero falla con dominio/CORS real. | Alta/Alto | topología same-origin aprobada y prueba en host TLS representativo antes de Sprint 1. |
| R0-05 | Divergencia MySQL local/CI/producción. | Media/Alto | 8.4 LTS exacto, strict mode, UTC y collation comunes; MySQL real en tests. |
| R0-06 | Health causa cascada o filtra infraestructura. | Media/Alto | checks acotados, respuesta mínima, rate limit y sin Entra/legacy en readiness. |
| R0-07 | “CodeQL” genera sensación falsa de cobertura PHP. | Alta/Alto | CodeQL sólo JS/TS y Actions; Semgrep + Larastan/PHPStan para PHP, documentado en branch rules. |
| R0-08 | Secretos llegan a PR de forks/logs/artefactos. | Media/Crítico | gitleaks, environments, permisos mínimos, ningún secreto en PR y artifact attestations. |
| R0-09 | OTel extension degrada PHP-FPM o no descarga spans cortos. | Media/Medio | benchmark, shutdown/flush, collector caído y sampling probados. |
| R0-10 | Storage local oculta requisitos de NFS/S3/AV. | Alta/Alto | port contract test; fake sólo en tests; promoción falla cerrada si scanner requerido no responde. |
| R0-11 | DDL monolítico y migraciones Laravel divergen. | Alta/Alto | schema dump normalizado y revisión DBA; una sola fuente ejecutable. |
| R0-12 | Imágenes/actions flotantes introducen supply-chain drift. | Media/Alto | digests/SHAs, lockfiles, Renovate y SBOM por commit. |

## 4. Arquitectura y alcance de Sprint 0

### Objetivo

Entregar una fundación reproducible, observable y verificable que levante backend Laravel 13, frontend Angular 22, Apache, MySQL 8.4, worker/scheduler, Mailpit y collector OTLP; ejecute todos los gates; genere el cliente; y exponga health, sin implementar ningún caso de negocio.

### Incluye

1. repositorio, owners, convenciones, scripts y pin de toolchain;
2. Laravel base y módulo técnico Shared;
3. bus síncrono de command/query, transacción, Problem Details y correlation ID;
4. infraestructura Outbox, auditoría, idempotencia y storage ports con pruebas técnicas;
5. health no versionado y telemetría de prueba;
6. shell Angular, runtime config, páginas error y plumbing HTTP;
7. contrato OpenAPI corregido, lint, diff y generación reproducible;
8. Compose local y cuatro workflows CI;
9. architecture tests y runbooks mínimos.

### Excluye

- OIDC real, provisioning, roles y Policies de negocio (Sprint 1/2);
- endpoints o UI de Residencies, Inventories y LaboratoryRequests;
- ETL productivo, dual-write, microservicios, broker, Redis, Passport o JWT frontend;
- despliegue productivo. El pipeline construye artefactos promovibles, pero no obtiene autoridad de producción.

### Paquetes de trabajo y entregables

| WP | Entregable |
|---|---|
| S0-01 | Repo, README raíz, `.editorconfig`, `.gitattributes`, CODEOWNERS, Dependabot/Renovate, `versions.env`. |
| S0-02 | Laravel 13, Shared module, providers/config, migrations técnicas, tests unit/integration/feature/architecture. |
| S0-03 | Outbox worker, lease/dead-letter, idempotency store, audit recorder y storage adapters sin caso de negocio. |
| S0-04 | Angular 22 shell, runtime config, functional interceptors, error pages, stores/facades base y generated client. |
| S0-05 | `api.yaml`, `management.yaml`, fragments planned, Redocly y OpenAPI Generator. |
| S0-06 | Compose, PHP-FPM, Apache, MySQL, Node, Mailpit, OTel Collector y volúmenes privados. |
| S0-07 | Workflows backend/frontend/openapi/security, SBOM y artifacts por SHA. |
| S0-08 | Runbooks bootstrap, deploy dry-run, rollback, restore, secret rotation e incident response. |

### Definition of Done

- En host limpio, un único comando de bootstrap termina sin pasos manuales y un segundo run es idempotente.
- `docker compose up --build` deja todos los servicios healthy; sólo Apache, Angular dev opcional y Mailpit local publican puertos en loopback.
- `/health/live` responde 200 aun con MySQL detenido; `/health/ready` cambia a 503 en menos de 5 s si falla una dependencia crítica y vuelve a 200 al recuperarse.
- `composer validate`, Pint, Larastan nivel máximo acordado, Deptrac, PHPUnit y migrations fresh pasan contra MySQL 8.4 real.
- ESLint sin warnings, tests, build production y budgets Angular pasan con Node 22.22.3.
- Redocly recommended-strict queda en cero; breaking diff se ejecuta contra el contrato de `main`; el cliente regenerado no ensucia Git.
- Outbox demuestra commit atómico, claim concurrente único, retry, lease recovery, dead-letter e idempotencia de un handler técnico de prueba.
- Auditoría demuestra append-only con grants; storage demuestra clave opaca, hash, private visibility y scanner fake contract-compatible.
- Logs JSON, trace y métrica de prueba llegan al collector con `service.name`, `service.version`, `trace_id` y correlation ID sin PII.
- SAST/SCA/secret scan/CodeQL aplicable/SBOM pasan; ningún Critical/High explotable queda abierto sin excepción aprobada.
- README y runbooks permiten a una persona ajena a la implementación reproducir el resultado.

### Dependencias

- Docker Desktop/Engine con Compose v2; Git; acceso a registries de Composer, npm y OCI.
- GitHub Actions y, para dependency review/code scanning privado, licencia/capacidad GitHub correspondiente.
- Owner DBA para revisar migraciones/grants; operación para Apache/TLS/OTLP; seguridad para gates.
- Dominio/host TLS representativo, secret store, storage/AV y tenant Entra no productivo pueden cerrarse durante Sprint 0, pero son entrada obligatoria de Sprint 1.

### Criterios de aceptación funcionales de la fundación

1. `GET /health/live` devuelve `{ "status": "ok" }` y `X-Correlation-ID` válido.
2. `GET /health/ready` devuelve 200 sólo cuando DB, storage y worker heartbeat están disponibles; nunca incluye hostname, DSN o excepción.
3. Una ruta inexistente de API devuelve RFC 9457, `application/problem+json` y correlation ID.
4. Una excepción controlada de prueba se redacta en respuesta y queda correlacionada en logs/traza.
5. Dos workers no reclaman el mismo evento; un lease vencido se recupera; al intento 10 se marca dead-letter.
6. Un `Idempotency-Key` repetido con mismo hash reproduce respuesta; con cuerpo diferente devuelve 409.
7. Un archivo de prueba queda fuera de `public`, con clave generada y SHA-256; un resultado infectado/fallido nunca se promociona.
8. La regeneración OpenAPI ejecutada dos veces produce bytes idénticos.

## 5. Estructura exacta del repositorio objetivo

```text
servicios.moderno/
├── .github/
│   ├── CODEOWNERS
│   ├── dependabot.yml
│   └── workflows/{backend.yml,frontend.yml,openapi.yml,security.yml}
├── backend/
│   ├── app/{Console,Exceptions,Http,Modules,Providers}/
│   ├── bootstrap/{app.php,providers.php}
│   ├── config/{app.php,auth.php,cors.php,database.php,filesystems.php,logging.php,modules.php,observability.php,outbox.php,sanctum.php,session.php}
│   ├── database/{factories,migrations,seeders}/
│   ├── public/index.php
│   ├── routes/{api.php,console.php,health.php,web.php}
│   ├── storage/
│   ├── tests/{Architecture,Contract,Feature,Integration,Support,Unit}/
│   ├── composer.json
│   ├── composer.lock
│   ├── deptrac.yaml
│   ├── phpstan.neon.dist
│   └── phpunit.xml
├── frontend/
│   ├── public/config/runtime-config.json
│   ├── src/{app,environments,styles}/
│   ├── angular.json
│   ├── eslint.config.js
│   ├── package.json
│   ├── package-lock.json
│   ├── tsconfig.json
│   ├── tsconfig.app.json
│   └── tsconfig.spec.json
├── openapi/
│   ├── api.yaml
│   ├── management.yaml
│   ├── components/{headers,parameters,responses,schemas,security}/
│   ├── fragments/planned/
│   ├── examples/
│   ├── generator/typescript-angular.yaml
│   ├── overlays/implemented.yaml
│   └── redocly.yaml
├── docs/
│   ├── adr/
│   ├── architecture/
│   ├── runbooks/{bootstrap,deploy,rollback,restore,secret-rotation,incident}.md
│   └── sprint/{sprint-0,sprint-1-entry}.md
├── docker/
│   ├── apache/{httpd.conf,vhosts.conf}
│   ├── php/{Dockerfile,php.ini,opcache.ini}
│   ├── mysql/init/{001-users.sql,002-grants.sql}
│   └── otel/collector.yaml
├── scripts/
│   ├── bootstrap.{sh,ps1}
│   ├── up.{sh,ps1}
│   ├── test.{sh,ps1}
│   ├── generate-client.{sh,ps1}
│   └── verify-clean.{sh,ps1}
├── .editorconfig
├── .env.example
├── .gitattributes
├── .gitignore
├── compose.yaml
├── README.md
└── versions.env
```

Justificación del acomodo: `openapi/` está al mismo nivel que backend/frontend porque es fuente independiente; `docker/` sólo contiene definición de imágenes/config y Compose queda en raíz; `scripts/` ofrece wrappers equivalentes para Linux/Windows; los documentos de discovery pueden copiarse a `docs/architecture`, conservando historial. No se versionan `.env`, certificados, secretos, storage, reports, coverage ni clientes generados temporales.

## 6. Backend Laravel

### 6.1 Estructura inicial real

Sprint 0 crea únicamente clases técnicas:

```text
backend/app/
├── Http/Middleware/{CorrelationIdMiddleware,ProblemDetailsMiddleware,RejectBearerTokenMiddleware}.php
├── Modules/Shared/
│   ├── Domain/
│   │   ├── Contracts/{Clock,TransactionManager}.php
│   │   ├── Events/DomainEvent.php
│   │   └── ValueObjects/{CorrelationId,Ulid}.php
│   ├── Application/
│   │   ├── Bus/{Command,CommandBus,Query,QueryBus}.php
│   │   └── Ports/{AuditRecorder,FileStorage,MalwareScanner,Outbox}.php
│   ├── Infrastructure/
│   │   ├── Bus/{SyncCommandBus,SyncQueryBus}.php
│   │   ├── Observability/{OpenTelemetryBootstrap,WorkerHeartbeat}.php
│   │   ├── Persistence/{DatabaseAuditRecorder,DatabaseOutbox,LaravelTransactionManager}.php
│   │   ├── Providers/SharedServiceProvider.php
│   │   ├── Storage/{LaravelPrivateFileStorage,FakeMalwareScanner}.php
│   │   └── Jobs/PublishOutboxBatch.php
│   └── Presentation/
│       ├── Http/Controllers/{LivenessController,ReadinessController}.php
│       └── Routes/health.php
└── Providers/ModulesServiceProvider.php
```

No se crean esqueletos de módulos de negocio. Los tests de arquitectura incluyen fixtures inválidos fuera de `app/` para demostrar que el gate realmente falla.

### 6.2 `composer.json` recomendado

Las restricciones de major son deliberadas; `composer.lock` fija la resolución exacta y es obligatorio.

```json
{
  "name": "institucion/servicios-moderno-backend",
  "type": "project",
  "license": "proprietary",
  "require": {
    "php": "^8.4",
    "ext-ctype": "*",
    "ext-curl": "*",
    "ext-fileinfo": "*",
    "ext-json": "*",
    "ext-mbstring": "*",
    "ext-openssl": "*",
    "ext-pdo": "*",
    "laravel/framework": "^13.17",
    "laravel/sanctum": "^4.3",
    "open-telemetry/api": "^1.8",
    "open-telemetry/exporter-otlp": "^1.4",
    "open-telemetry/opentelemetry-auto-laravel": "^1.9",
    "open-telemetry/sdk": "^1.8"
  },
  "require-dev": {
    "fakerphp/faker": "^1.24",
    "larastan/larastan": "^3.11",
    "laravel/pint": "^1.27",
    "mockery/mockery": "^1.6",
    "nunomaduro/collision": "^8.6",
    "phpunit/phpunit": "^12.5",
    "deptrac/deptrac": "^4.7"
  },
  "autoload": { "psr-4": { "App\\": "app/" } },
  "autoload-dev": { "psr-4": { "Tests\\": "tests/" } },
  "scripts": {
    "lint": ["pint --test", "phpstan analyse --no-progress", "deptrac analyse --no-progress"],
    "test": "phpunit",
    "test:architecture": "phpunit tests/Architecture",
    "test:contract": "phpunit tests/Contract",
    "quality": ["@lint", "@test"]
  },
  "config": {
    "allow-plugins": { "php-http/discovery": true },
    "audit": { "abandoned": "fail" },
    "optimize-autoloader": true,
    "preferred-install": "dist",
    "sort-packages": true
  },
  "minimum-stability": "stable",
  "prefer-stable": true
}
```

Antes de congelar el lock se ejecuta una resolución real contra Laravel 13 y PHP 8.4. `ext-opentelemetry` se instala en la imagen PHP, porque la auto-instrumentación oficial lo requiere. Si la extensión no supera el benchmark, se conserva API/SDK e instrumentación manual de fronteras; no se sustituye OpenTelemetry.

### 6.3 Librerías

| Clasificación | Librería/capacidad | Motivo |
|---|---|---|
| Obligatoria | `laravel/framework` | Framework aprobado. |
| Obligatoria | `laravel/sanctum` | Sesión first-party; se usa sólo modo SPA cookie. |
| Obligatoria | OpenTelemetry API/SDK/OTLP + auto Laravel | Trazas/métricas vendor-neutral; código propio depende preferentemente de API. |
| Obligatoria dev | PHPUnit, Pint, Larastan/PHPStan, Deptrac | tests, estilo, tipos y fronteras. |
| Opcional Sprint 1 | cliente OIDC seleccionado por spike | No elegir Socialite/MSAL por comodidad si no prueba validaciones OIDC completas. |
| Opcional | `league/flysystem-aws-s3-v3` | Sólo si storage objetivo es S3 compatible. |
| Opcional | cliente ClamAV mantenido | Sólo tras seleccionar servicio AV; el port permanece estable. |
| Opcional | Infection | Mutation testing selectivo en invariantes/policies desde el primer slice. |
| No recomendado | Passport, JWT app, Spatie Event Sourcing, activitylog genérico | Contradicen decisiones o duplican una necesidad semántica específica. |

### 6.4 Providers y configuración modular

`bootstrap/providers.php` registra `AppServiceProvider`, `ObservabilityServiceProvider` y `ModulesServiceProvider`. Este último lee `config/modules.php`, valida clases únicas y registra sólo módulos habilitados. Cada provider de módulo:

- `register()`: bindings de ports/adapters y mapas command/query; sin I/O;
- `boot()`: rutas, migrations, policies y health contributors;
- no usa auto-discovery por escaneo de filesystem en producción;
- falla al arrancar si hay command/query duplicado o adapter faltante.

`config/modules.php` contiene una lista ordenada de providers y dependencias declaradas. En Sprint 0 sólo Shared está habilitado. Los nombres futuros pueden estar documentados, no cargados.

### 6.5 CQRS ligero

- `CommandBus::dispatch(Command): mixed` y `QueryBus::ask(Query): mixed` son interfaces Application.
- Los buses síncronos usan un mapa `message class => handler class` resuelto por Container; no reflection discovery.
- Cada command tiene un handler y se ejecuta mediante un decorador transaccional; una query nunca inicia una transacción de escritura.
- Los controllers crean DTO/command explícitos y nunca reciben Eloquent.
- Domain events se recolectan desde el agregado y se persisten en Outbox dentro de la misma transacción.
- No se instala un paquete CQRS: el comportamiento requerido es pequeño y el contrato propio evita acoplar Application a Laravel.

### 6.6 Outbox

Tabla mínima: `id`, `context`, `event_type`, `event_version`, `aggregate_type`, `aggregate_id`, `aggregate_version`, `payload`, `occurred_at`, `correlation_id`, `actor_id`, `state`, `available_at`, `locked_until`, `locked_by`, `attempts`, `published_at`, `failed_at`, `last_error_redacted`.

Algoritmo: seleccionar lote pendiente disponible con `FOR UPDATE SKIP LOCKED`; marcar lease y commit; ejecutar handlers fuera del lock; registrar inbox/efecto idempotente; marcar publicado. Error incrementa intentos y agenda backoff; el décimo fallo pasa a dead-letter. Un comando de replay exige motivo, permiso futuro y audit record. Nunca se serializan modelos Eloquent ni secretos.

### 6.7 Auditoría

`AuditRecorder` recibe actor lógico nullable, acción, tipo/id de sujeto, correlation ID y metadata allowlisted. Se inserta en la transacción del comando. La aplicación no ofrece update/delete ni endpoint CRUD. El migrator puede administrar esquema; runtime sólo inserta/consulta según rol. El hash encadenado o firma externa queda como extensión si compliance lo exige; no se declara integridad criptográfica inexistente.

### 6.8 Storage

Discos: `staging` privado y efímero; `private` permanente; ambos fuera de `public`. Flujo: stream con límite → nombre opaco → MIME real → SHA-256 → metadata PENDIENTE → AV → promoción atómica → LIMPIO. INFECTADO/FALLIDO se aísla y nunca se descarga. El adapter local implementa el mismo contract que NFS/S3. URLs firmadas son detalle de adapter; autorización siempre ocurre antes.

### 6.9 OpenTelemetry y logging

- OTLP/HTTP hacia collector; W3C `traceparent`; `service.name=servicios-backend` y `service.version=$GIT_SHA`.
- Correlation ID UUID validado (longitud y caracteres) o generado; se devuelve en toda respuesta y se agrega a logs/traces/jobs/outbox.
- logs JSON a stdout; redacción de cookies, authorization, codes OIDC, PII, documentos y payloads.
- métricas base: requests/duración/5xx, DB pool/query duration, queue depth/failures, outbox age/retries/dead, worker heartbeat, storage failures.
- collector inaccesible no rompe requests; exportación tiene timeout y cola acotada.

### 6.10 Sanctum

- ejecutar `install:api`, conservar middleware stateful y **eliminar/no publicar** personal access tokens si no se usan;
- `statefulApi()` en `bootstrap/app.php`; `auth:sanctum` desde Sprint 1;
- session y cache database desde Sprint 0; cookies `HttpOnly`, `Secure` en TLS, `SameSite=Lax`, nombre por ambiente;
- XSRF cookie legible por Angular y header enviado automáticamente; CORS credentials sólo si una topología distinta de same-origin fue aprobada;
- no `HasApiTokens`, no endpoint token, no bearer fallback; test que rechaza `Authorization`.

## 7. Frontend Angular

### 7.1 Estructura Sprint 0

```text
frontend/src/app/
├── app.component.{ts,html,scss}
├── app.config.ts
├── app.routes.ts
├── core/
│   ├── auth/{auth.facade.ts,auth.models.ts,auth.store.ts}
│   ├── config/{runtime-config.initializer.ts,runtime-config.schema.ts,runtime-config.token.ts}
│   ├── errors/{global-error-handler.ts,problem-details.ts}
│   ├── guards/{anonymous.guard.ts,auth.guard.ts,permission.guard.ts,scope.guard.ts}
│   ├── http/{api-error.interceptor.ts,correlation-id.interceptor.ts,credentials.interceptor.ts}
│   └── layout/{app-shell,access-denied,not-found,service-unavailable}/
├── shared/
│   ├── api/generated/
│   ├── forms/server-errors.ts
│   ├── testing/
│   └── ui/{loading-indicator,status-message}/
└── styles/{_tokens.scss,_reset.scss,_typography.scss}
```

No se crean `features/residencies`, `features/inventories` ni `features/laboratory-requests`. Se agregan por slice. Auth store existe como contrato inerte (`unknown/authenticated/anonymous/error`) sin OIDC real.

### 7.2 `angular.json` baseline

```json
{
  "$schema": "./node_modules/@angular/cli/lib/config/schema.json",
  "version": 1,
  "newProjectRoot": "projects",
  "projects": {
    "servicios": {
      "projectType": "application",
      "root": "",
      "sourceRoot": "src",
      "prefix": "app",
      "architect": {
        "build": {
          "builder": "@angular/build:application",
          "options": {
            "browser": "src/main.ts",
            "tsConfig": "tsconfig.app.json",
            "assets": [{ "glob": "**/*", "input": "public" }],
            "styles": ["src/styles.scss"]
          },
          "configurations": {
            "production": {
              "outputHashing": "all",
              "sourceMap": false,
              "budgets": [
                { "type": "initial", "maximumWarning": "350kB", "maximumError": "450kB" },
                { "type": "anyComponentStyle", "maximumWarning": "6kB", "maximumError": "10kB" }
              ]
            },
            "development": { "optimization": false, "sourceMap": true }
          },
          "defaultConfiguration": "production"
        },
        "serve": {
          "builder": "@angular/build:dev-server",
          "configurations": {
            "production": { "buildTarget": "servicios:build:production" },
            "development": { "buildTarget": "servicios:build:development" }
          },
          "defaultConfiguration": "development"
        },
        "test": { "builder": "@angular/build:unit-test" }
      }
    }
  },
  "cli": { "analytics": false }
}
```

`package.json` fija Angular/CLI 22.0.x, TypeScript 6.0.x, RxJS 7.8.x y engines Node `22.22.3`. El lockfile manda. `strict`, `strictTemplates`, `noUncheckedIndexedAccess`, `noImplicitOverride` y `useDefineForClassFields` quedan activos.

### 7.3 Cliente OpenAPI

Generador fijado: OpenAPI Generator 7.22.0 vía imagen por digest. Config:

```yaml
generatorName: typescript-angular
inputSpec: /local/openapi/api.yaml
outputDir: /local/frontend/src/app/shared/api/generated
additionalProperties:
  ngVersion: 22.0.0
  providedIn: root
  modelPropertyNaming: original
  paramNaming: camelCase
  stringEnums: true
  taggedUnions: true
  useSingleRequestParameter: true
  withInterfaces: true
```

El script borra únicamente el directorio generated tras validar su ruta absoluta, genera en temporal, normaliza, reemplaza y comprueba `git diff --exit-code`. Nunca se parchea código generado. El soporte OAS 3.1 del generador sigue siendo beta: los tests de compilación y de schemas discriminados son gate explícito.

### 7.4 Interceptores, guards, stores y facades

Orden de interceptores funcionales: correlation → credentials/XSRF allowlisted → error normalization. No hay retry global. Sólo una facade decide retry de GET idempotente respetando `Retry-After` y jitter.

- `credentialsInterceptor`: sólo URLs de `apiBaseUrl`, `withCredentials=true`; rechaza host inesperado.
- `correlationIdInterceptor`: UUID por interacción, propaga uno válido, nunca CR/LF.
- `apiErrorInterceptor`: valida content type/shape RFC 9457; preserva status y fallback seguro.
- `auth.guard`: espera resolución de sesión; redirige sólo a ruta local saneada.
- `permission.guard`/`scope.guard`: UX, nunca autoridad; el 403 backend prevalece.
- stores: `signal` privado, `computed` público, estado inmutable; no Subject-store paralelo.
- facades: única frontera de páginas hacia generated client; devuelven signals para lectura y métodos/observables acotados para comandos.

Runtime config se carga antes de bootstrap, se valida por schema y contiene sólo `apiBaseUrl`, `environment`, `version` y flags no secretos. En producción `apiBaseUrl` es relativo/same-origin.

## 8. OpenAPI objetivo de Sprint 0

### Archivos y gates

- `management.yaml`: `/health/live` y `/health/ready`, sin auth, no se genera cliente UI.
- `api.yaml`: contrato desplegable `/api/v1`; en Sprint 0 puede no contener operaciones de negocio.
- `fragments/planned/`: diseño no publicado de slices futuros; no se usa para afirmar disponibilidad.
- `redocly.yaml`: `recommended-strict`; operationId único, kebab paths, tags/summaries/descriptions, license y ejemplos obligatorios.
- breaking check: `oasdiff breaking` contra `origin/main:openapi/api.yaml` y management.
- contract tests: backend response validada contra bundle y generated Angular compile.

Antes de Sprint 1 se agregan al contrato desplegable `/me` y schemas IAM completamente tipados. Las rutas web `/auth/entra/*` no se meten artificialmente bajo `/api/v1`; se documentan en `web-auth.yaml` o como parte no generada con servidor raíz.

## 9. Infraestructura local reproducible

### Versiones elegidas

| Componente | Baseline |
|---|---|
| PHP | 8.4.x FPM Bookworm, patch y digest fijados |
| Laravel | 13.x, lockfile |
| Node | 22.22.3 Bookworm slim |
| Angular | 22.0.x, lockfile |
| MySQL | 8.4.x LTS, patch y digest fijados |
| Apache | 2.4.68 o parche superior de la misma línea, digest fijado |
| Mailpit | 1.31.1, sólo local y ligado a loopback |
| OTel Collector | contrib release fijada por digest |

### Compose mínimo

```yaml
name: servicios-moderno
services:
  apache:
    image: httpd:2.4.68-bookworm
    ports: ["127.0.0.1:8080:80"]
    volumes:
      - ./docker/apache/httpd.conf:/usr/local/apache2/conf/httpd.conf:ro
      - ./docker/apache/vhosts.conf:/usr/local/apache2/conf/extra/vhosts.conf:ro
      - ./backend/public:/srv/backend/public:ro
      - ./frontend/dist/servicios/browser:/srv/frontend:ro
    depends_on:
      php: { condition: service_healthy }

  php:
    build: { context: ., dockerfile: docker/php/Dockerfile }
    working_dir: /srv/backend
    env_file: [.env]
    volumes:
      - ./backend:/srv/backend
      - private_storage:/srv/backend/storage/app/private
      - staging_storage:/srv/backend/storage/app/staging
    depends_on:
      mysql: { condition: service_healthy }
      otel-collector: { condition: service_started }
    healthcheck:
      test: ["CMD", "php-fpm-healthcheck"]
      interval: 10s
      timeout: 3s
      retries: 10

  worker:
    build: { context: ., dockerfile: docker/php/Dockerfile }
    command: ["php", "artisan", "queue:work", "database", "--sleep=1", "--tries=1", "--timeout=60"]
    working_dir: /srv/backend
    env_file: [.env]
    volumes:
      - ./backend:/srv/backend
      - private_storage:/srv/backend/storage/app/private
      - staging_storage:/srv/backend/storage/app/staging
    depends_on:
      mysql: { condition: service_healthy }

  scheduler:
    build: { context: ., dockerfile: docker/php/Dockerfile }
    command: ["php", "artisan", "schedule:work"]
    working_dir: /srv/backend
    env_file: [.env]
    volumes: ["./backend:/srv/backend"]
    depends_on:
      mysql: { condition: service_healthy }

  mysql:
    image: mysql:8.4.12
    environment:
      MYSQL_DATABASE: servicios_moderno
      MYSQL_USER: servicios_runtime
      MYSQL_PASSWORD: local-only-change-me
      MYSQL_ROOT_PASSWORD: local-root-only-change-me
      TZ: UTC
    command: ["--character-set-server=utf8mb4", "--collation-server=utf8mb4_0900_ai_ci", "--default-time-zone=+00:00"]
    volumes: ["mysql_data:/var/lib/mysql", "./docker/mysql/init:/docker-entrypoint-initdb.d:ro"]
    healthcheck:
      test: ["CMD-SHELL", "mysqladmin ping -h 127.0.0.1 -uroot -p$$MYSQL_ROOT_PASSWORD --silent"]
      interval: 5s
      timeout: 3s
      retries: 30

  node:
    image: node:22.22.3-bookworm-slim
    profiles: ["tools"]
    working_dir: /workspace/frontend
    volumes: ["./frontend:/workspace/frontend"]

  mailpit:
    image: axllent/mailpit:v1.31.1
    ports: ["127.0.0.1:8025:8025"]
    expose: ["1025"]

  otel-collector:
    image: otel/opentelemetry-collector-contrib:<pin-by-digest>
    command: ["--config=/etc/otelcol/config.yaml"]
    volumes: ["./docker/otel/collector.yaml:/etc/otelcol/config.yaml:ro"]
    expose: ["4318"]

volumes:
  mysql_data:
  private_storage:
  staging_storage:
```

El placeholder del collector se resuelve a tag+digest durante bootstrap del repositorio; no se acepta en `main`. Los passwords mostrados son sólo defaults locales en `.env.example`, nunca reutilizables. CI usa secretos efímeros distintos. Mailpit no forma parte del despliegue.

Apache sirve el bundle Angular con fallback sólo para rutas UI; `/api/v1`, `/auth`, `/sanctum` y `/health` se proxyan explícitamente a PHP-FPM/front controller. Se niegan dotfiles, vendor, storage y source maps; límites y headers se prueban.

El Compose base puede exponer HTTP sólo en loopback para bootstrap sin IAM. Un override `compose.tls.yaml` genera/usa una CA local no versionada y expone `https://localhost:8443`; toda prueba de cookie `Secure`, callback, CORS y XSRF se ejecuta en ese perfil TLS. Nunca se debilita `Secure` en un ambiente compartido.

## 10. CI/CD

### `backend.yml`

Triggers PR/push a `main` con paths backend/docker. Jobs:

1. `static`: checkout SHA-pinned, PHP 8.4 con extensiones, Composer cache por lock hash, `composer install --no-interaction --prefer-dist`, validate strict, Pint, Larastan y Deptrac.
2. `test`: service MySQL 8.4 exacto; copia `.env.testing`, key, `migrate:fresh`; PHPUnit unit/integration/feature/contract; verifica grants y schema; coverage Domain/Application >=80% cuando existan.
3. `build`: sólo tras gates; `composer install --no-dev --classmap-authoritative`, crea tar sin `.env/tests/storage runtime`, checksum y artifact por commit SHA.

### `frontend.yml`

Node 22.22.3, `npm ci --ignore-scripts` seguido de allowlist explícita de scripts necesarios, format check, ESLint `--max-warnings=0`, unit/component, build production y budget. El artifact `frontend-$SHA` contiene sólo `dist` y checksum.

### `openapi.yml`

Redocly fijado, `recommended-strict`, bundle, `oasdiff breaking` contra `main`, generación TypeScript Angular en temporal, compilación y `git diff --exit-code`. Un cambio breaking exige major nuevo y ADR/plan de deprecación; no se ignora con baseline generado.

### `security.yml`

- gitleaks en historial PR relevante;
- `composer audit --locked --abandoned=fail` y `npm audit --audit-level=high`;
- dependency-review en PR cuando la licencia GitHub lo permita;
- Semgrep reglas PHP/Laravel/OWASP y reglas TS/Angular;
- CodeQL `javascript-typescript` y `actions` con `security-extended`; **PHP no es lenguaje soportado por CodeQL** y se cubre con Semgrep + Larastan;
- CycloneDX Composer/npm y Syft SPDX del artefacto; upload por SHA;
- Trivy/Grype sobre imágenes finales; gate Critical/High con política de excepción fechada;
- permisos workflow mínimos: `contents: read`; `security-events: write` sólo CodeQL; `id-token: write` sólo attestation/deploy.

Todos los `uses:` se fijan por SHA completo. Los workflows de PR no acceden a environments ni secretos. `main` repite gates, construye una vez y atestigua; deploy descarga exactamente esos artifacts, nunca recompila.

Branch rules requeridas: PR, CODEOWNERS, conversaciones resueltas, commits firmados según política, checks backend/frontend/openapi/security, no force push, no bypass salvo break-glass auditado. Environments: development, staging, production con aprobación y protección crecientes.

## 11. Arquitectura de pruebas

### 11.1 Dependencias entre módulos — Deptrac

```yaml
deptrac:
  paths: [app/Modules]
  exclude_files: ['#Tests#']
  layers:
    - name: SharedDomain
      collectors: [{ type: classLike, value: '^App\\Modules\\Shared\\Domain\\' }]
    - name: SharedApplication
      collectors: [{ type: classLike, value: '^App\\Modules\\Shared\\Application\\' }]
    - name: SharedInfrastructure
      collectors: [{ type: classLike, value: '^App\\Modules\\Shared\\Infrastructure\\' }]
    - name: SharedPresentation
      collectors: [{ type: classLike, value: '^App\\Modules\\Shared\\Presentation\\' }]
    - name: Illuminate
      collectors: [{ type: classLike, value: '^Illuminate\\' }]
  ruleset:
    SharedDomain: []
    SharedApplication: [SharedDomain]
    SharedInfrastructure: [SharedDomain, SharedApplication, Illuminate]
    SharedPresentation: [SharedDomain, SharedApplication, Illuminate]
```

Al introducir un módulo se agregan cuatro layers y sólo sus dependencias aprobadas. `Domain` nunca permite `Illuminate`, Infrastructure ni Presentation. La configuración falla por violaciones no cubiertas; no se crea baseline global.

### 11.2 PHPUnit: prohibición de Illuminate en Domain

```php
#[Test]
public function domain_does_not_import_illuminate(): void
{
    foreach (PhpFiles::under(app_path('Modules'), '/Domain/') as $file) {
        self::assertDoesNotMatchRegularExpression(
            '/\\b(?:use|extends|implements|new)\\s+\\\\?Illuminate\\\\/m',
            $file->contents(),
            $file->path(),
        );
    }
}
```

El helper usa `nikic/php-parser` o tokens, no sólo grep, para inspeccionar nombres resueltos; el regex mostrado comunica la expectativa, no sustituye el parser del test real.

### 11.3 PHPUnit: Eloquent entre módulos

```php
#[Test]
public function a_module_never_imports_another_modules_eloquent_namespace(): void
{
    foreach (ResolvedImports::from(app_path('Modules')) as $source => $imports) {
        $owner = ModuleName::fromPath($source);
        foreach ($imports as $import) {
            if (preg_match('/^App\\\\Modules\\\\([^\\\\]+)\\\\Infrastructure\\\\Persistence\\\\Eloquent\\\\/', $import, $m)) {
                self::assertSame($owner, $m[1], "$source imports foreign Eloquent type $import");
            }
        }
    }
}
```

Otro test aplica la matriz de `ARQUITECTURA_LARAVEL.md:326-334` y permite cruces sólo hacia `Application/Published`, eventos publicados o adapters que implementan ports del consumidor. Se incluyen fixtures que prueban un fallo real del gate.

### 11.4 Contratos OpenAPI

- lint/bundle estricto y resolución de `$ref`;
- uniqueness de `operationId`, error RFC 9457, ULID uppercase, headers correlation/ETag y respuestas comunes;
- Schemathesis o runner equivalente contra backend efímero para health y, desde cada slice, operaciones nuevas;
- PHPUnit valida response real contra schema bundled;
- Angular generated client compila sin casts/parches;
- snapshot sólo del bundle normalizado, no de respuestas dinámicas;
- oasdiff bloquea breaking changes no versionados.

### 11.5 Pirámide Sprint 0

| Nivel | Pruebas mínimas |
|---|---|
| Unit | ULID/correlation, backoff, state machines técnicas, error mapping, stores/interceptors. |
| Integration | MySQL migrations/grants, transactions, outbox concurrency/lease, audit, storage. |
| Feature | health, Problem Details, headers, rechazo bearer, CSRF baseline. |
| Contract | OpenAPI ↔ backend y generated client. |
| E2E smoke | Apache sirve SPA; SPA obtiene runtime config; health accesible; 404 correcto. |
| Chaos técnico | detener DB/collector/worker y comprobar readiness, recuperación y no pérdida. |

## 12. Checklist de validación Sprint 0

### Repositorio y toolchain

- [ ] Repo moderno separado del CI4 y con historial/owners propios.
- [ ] PHP, Node, MySQL, Apache, generator, collector e imágenes fijados por versión/digest.
- [ ] `composer.lock` y `package-lock.json` versionados; build sin dependencias flotantes.
- [ ] bootstrap Linux y PowerShell probados en máquinas limpias.

### Backend

- [ ] Sólo Shared implementado; no lógica de negocio.
- [ ] Providers deterministas, maps CQRS únicos y config cache/route cache pasan.
- [ ] MySQL fresh y upgrade desde último artifact pasan.
- [ ] Outbox/inbox/lease/retry/dead-letter y worker heartbeat demostrados.
- [ ] Auditoría append-only y usuarios runtime/migrator verificados.
- [ ] Storage privado/staging y scanner contract test pasan.
- [ ] Sanctum cookie mode sin personal tokens/bearer habilitado.

### Frontend y contrato

- [ ] Angular strict/standalone/Signals/RxJS; shell y errores accesibles.
- [ ] Runtime config validado y sin secretos.
- [ ] Interceptores y guards tienen allow/deny/error tests.
- [ ] Redocly cero errores/warnings y oasdiff activo.
- [ ] Cliente generado reproducible, tipado y sin edición manual.

### Infra, observabilidad y seguridad

- [ ] Compose health y recuperación probados; Mailpit/DB no expuestos fuera de loopback.
- [ ] Health no filtra detalle y diferencia live/ready correctamente.
- [ ] Logs/traces/métricas correlacionados y redactados; collector caído no derriba API.
- [ ] SAST, SCA, secrets, CodeQL aplicable, SBOM y image scan son required checks.
- [ ] Artifacts por SHA con checksum/attestation; deploy no recompila.
- [ ] Runbooks bootstrap, rollback, restore, secrets e incidente revisados.

## 13. Checklist para iniciar Sprint 1 — Microsoft Entra ID

### Precondiciones obligatorias

- [ ] Sprint 0 DoD completo y sin excepciones Critical/High.
- [ ] Enum IAM único y migraciones IAM claramente asignadas a Sprint 1.
- [ ] App Registration Web por ambiente; tenant único, redirect exacto y sin implicit grant.
- [ ] Same-origin o prueba aprobada de session domain/CORS/XSRF en TLS real.
- [ ] Secret store elegido; owner, expiración y rotación de secreto/certificado documentados.
- [ ] Claims reales de usuarios de prueba capturados de forma redactada; `(tid,oid)` confirmado.
- [ ] Política de `state`, `nonce`, PKCE, clock skew, JWKS cache/rotation e issuer allowlist aprobada.
- [ ] Paquete OIDC seleccionado mediante spike y SCA; no Passport, JWT app ni token en Angular.
- [ ] Estados provisioning y comportamiento de identidad suspendida/desactivada aprobados.
- [ ] Matriz de usuarios de prueba: activo, pendiente, suspendido, tenant incorrecto, grupo overage y claims faltantes.
- [ ] `/me` y Problem Details IAM añadidos primero a OpenAPI y cliente regenerado.
- [ ] Observabilidad de login definida sin registrar code, token, claims completos, correo u otros PII.

### Riesgos específicos de Sprint 1

- callback replay/forjado; redirect abierto; mezcla de tenants; cache JWKS obsoleta;
- session fixation, cookie domain incorrecto, CSRF intermitente y logout incompleto;
- deduplicación incorrecta por correo y colisión con `UNIQUE correo_normalizado`;
- Graph convertido accidentalmente en dependencia de login;
- grupo overage interpretado como ausencia de permisos;
- secreto OIDC accesible a Angular, PR o logs.

### Criterios de entrada

Sprint 1 puede iniciar sólo si: (a) existe conectividad HTTPS desde backend al tenant de prueba; (b) el callback exacto está registrado; (c) session/XSRF funciona en la topología objetivo; (d) el contrato `/me` está aprobado; (e) el paquete OIDC demuestra todas las validaciones negativas; y (f) seguridad/operación aceptan el plan de secretos y telemetría.

## 14. Fuentes técnicas verificadas para el baseline

- [Laravel 13 release notes](https://laravel.com/framework/docs/13.x/releases): requiere PHP 8.3 mínimo; se selecciona PHP 8.4 por decisión operativa. La [política de versiones](https://laravel.com/framework/docs/releases) recomienda restricciones de major como `^13.0`.
- [Matriz oficial Angular](https://angular.dev/reference/versions): Angular 22.0 requiere Node `^22.22.3 || ^24.15.0 || ^26.0.0`, TypeScript `>=6.0 <6.1` y RxJS `^6.5.3 || ^7.4.0`; se conserva Node 22 y RxJS 7.
- [Laravel Sanctum 13.x](https://laravel.com/framework/docs/13.x/sanctum): SPA usa sesión cookie, exige mismo top-level domain, stateful middleware y CSRF; no necesita personal access tokens.
- [OpenTelemetry PHP](https://opentelemetry.io/docs/languages/php/) declara trazas, métricas y logs estables; la [instrumentación Laravel](https://packagist.org/packages/open-telemetry/opentelemetry-auto-laravel) requiere la extensión y soporta Laravel 13.
- [Modelo de releases MySQL](https://dev.mysql.com/doc/refman/8.4/en/mysql-releases.html): MySQL 8.4 es línea LTS; se evita iniciar un sistema nuevo sobre MySQL 8.0 fuera de su ventana normal.
- [Lenguajes soportados por CodeQL](https://docs.github.com/en/code-security/reference/code-scanning/workflow-configuration-options): incluye JavaScript/TypeScript y GitHub Actions, pero no PHP; de ahí la cobertura complementaria explícita.
- [OpenAPI Generator TypeScript Angular](https://openapi-generator.tech/docs/generators/typescript-angular/) soporta Angular 22; el proyecto declara OpenAPI 3.1 como soporte beta, por lo que el compile/contract test es obligatorio.
- [Redocly lint](https://redocly.com/docs/cli/commands/lint): el ruleset recomendado valida estructura y convenciones; Sprint 0 eleva warnings con `recommended-strict`.
