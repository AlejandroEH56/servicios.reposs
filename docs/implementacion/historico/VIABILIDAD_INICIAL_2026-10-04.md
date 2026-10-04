# Viabilidad e inicio de modernización en servicios.reposs

Evaluación: 2026-10-04, America/Mexico_City. Proyecto evaluado: `C:/GitHubRepositories/servicios.reposs`.

**Viable para continuar Sprint 0. NO-GO para Sprint 1.** La arquitectura puede implementarse en el Laravel existente sin insertar Laravel dentro del CodeIgniter de `servicios.proyecto`. Se inició la fundación local y se instalaron las dependencias necesarias para comprobarla. Esto no equivale a cerrar el Entry Gate.

## Fuentes y alcance

Se aplicaron [PLAN_RESOLUCION_PRE_SPRINT_1.md](PLAN_RESOLUCION_PRE_SPRINT_1.md), [PRE_SPRINT_1_CHECKLIST.md](PRE_SPRINT_1_CHECKLIST.md), [ARQUITECTURA_LARAVEL.md](ARQUITECTURA_LARAVEL.md), [PLAN_EJECUCION.md](PLAN_EJECUCION.md), API, Angular, IAM, seguridad, ADR-001 a ADR-011 y los análisis DDD/datos/ER referenciados. Las copias conservan el diagnóstico histórico de **otro repositorio**; sus resultados de 2026-09-27 no son resultados del Laravel actual.

[SOURCES_MANIFEST.json](SOURCES_MANIFEST.json) identifica las fuentes copiadas con SHA-256. El DDL en `database/mysql/001_create_servicios_moderno.sql` es referencia documental; no se ejecutó y no se convierte automáticamente en migración.

La raíz de `servicios.reposs` ya es el backend. Por tanto, aquí `app/`, `config/`, `routes/` y `database/` corresponden al `backend/` de los documentos. No se creó un segundo Laravel anidado. Angular está en `frontend/`, separado del backend. Esta adaptación física conserva la separación respecto del legacy; debe incorporarse al criterio del checklist cuando se apruebe la estructura del repositorio destino.

## Estado inicial observado

- Laravel existente: 13.34.0, restricción `^13.17`, con `composer.lock` y `vendor/`.
- Sólo había rutas y pruebas de ejemplo; no había módulos, health objetivo, OpenAPI operativo ni frontend Angular.
- PHP y Composer no estaban disponibles en PATH. Composer 2.10.3 ya existía en `C:/ProgramData/ComposerSetup/bin`; faltaba PHP ejecutable en el entorno disponible.
- El primer test HTTP falló por ausencia de `APP_KEY`. Se generó la clave local cuando estaba vacía; no se publica su valor.
- Node 24.19.0 y npm 11.17.0 estaban disponibles. Docker no estaba disponible. El cliente MySQL local identifica 26.7; no acredita la versión MySQL objetivo ni sus permisos.
- `servicios.reposs` no tiene `.git`. En `servicios.proyecto`, `docs/` y `database/` siguen sin seguimiento. No hay SHA del destino, remote ni ejecución CI que permita declarar reproducibilidad desde clon.
- Persisten `User`, factory y migraciones de autenticación password del esqueleto Laravel. No son el modelo IAM aprobado y no deben promoverse como identidad institucional. No se ejecutaron migraciones.

## Cambios realizados

1. PHP 8.4.26 NTS x64 instalado en `.tools/php`, con SHA-256 contrastado con PHP oficial. Se habilitaron extensiones necesarias, incluidas PDO MySQL/SQLite, mbstring, OpenSSL, fileinfo, intl y zip. No se modificó el PATH global. Composer se copió a `.tools/composer.phar`.
2. `scripts/install-local-tools.ps1` reproduce la descarga con versiones y checksums; `scripts/use-local-tools.ps1` habilita el entorno de la sesión. Composer resuelve contra `config.platform.php=8.4.26`.
3. Laravel Boost existente quedó configurado para Codex mediante `boost:install --guidelines`; se leyeron las instrucciones generadas.
4. Se instalaron Sanctum 4.3.3 y Larastan 3.12.2/PHPStan 2.2.16. Sanctum queda como dependencia: todavía no hay login Entra, provisioning, `/api/v1/me`, policies ni configuración de topología aprobada. No se seleccionó cliente OIDC.
5. Se instaló Redocly 2.57.0. La versión 2.14.3 citada por el análisis anterior produjo 14 alertas npm; la actualización eliminó esas alertas. No se aplicaron excepciones para ocultarlas.
6. Se implementaron `/health/live` y `/health/ready`, sin prefijo API y sin middleware de sesión. Readiness usa un port de Application con adapter en Shared Infrastructure, registrado por el provider de Shared.
7. Se agregó `X-Correlation-ID`, validando UUID o generándolo, y respuestas RFC 9457 para errores de `api/*` y `health/*`. Se ocultan excepción, SQL, query string y valores de validación; se conservan headers necesarios como `Retry-After` y `Allow`. El formato inicial usa `about:blank`; el catálogo institucional de tipos y errores de campos queda pendiente.
8. `openapi/management.yaml` declara sólo health implementado y valida con `recommended-strict`. El diseño original se conserva en `openapi/fragments/planned/legacy-design.yaml`, excluido del lint/bundle desplegable. No se crea un API `/me` sin aprobación de B02/B05.
9. Se agregó el shell Angular 22.2.1 standalone con routing, Signals, TypeScript 6.0.3 y RxJS 7.8.2. Versiones directas exactas y lockfile; `strict` y `strictTemplates` explícitos. `.nvmrc` fija Node 24.19.0 para desarrollo. El pin Node 24 debe ser ratificado/alineado con CI y operación; la recomendación Node 22 del documento original no se cambia silenciosamente.
10. Se agregaron pruebas de health, fallos/recuperación mediante doubles, redacción HTTP, correlación, correspondencia entre contrato y rutas y restricciones arquitectónicas de módulos. `phpunit.xml` tiene una clave exclusivamente de pruebas para no depender de la clave local. No se usó SQLite como sustituto de una integración MySQL.

## Verificación local

| Verificación | Resultado y límite |
|---|---|
| PHP / Composer | 8.4.26 / 2.10.3 ejecutables; extensiones de plataforma verificadas |
| `composer validate --strict` | PASS |
| `composer check-platform-reqs` | PASS |
| `php vendor/bin/phpunit` | PASS: 16 pruebas, 64 aserciones; JUnit en `artifacts/phpunit.xml` |
| `composer analyse` | PASS: PHPStan nivel 6, cero errores, sin baseline de supresiones |
| Pint | PASS sobre fuentes modificadas; `--dirty` no está disponible porque no existe Git en el destino |
| `npm run openapi:lint` y `openapi:bundle` | PASS: management strict, cero errores/advertencias; bundle en `artifacts/management.yaml` |
| `npm run build` | PASS: assets Vite del backend. El esqueleto descarga fuentes Bunny y requiere salida HTTPS durante ese build |
| `composer audit` | PASS: sin advisories ni paquetes abandonados reportados |
| `npm audit` raíz y frontend durante instalación | PASS: cero vulnerabilidades reportadas |
| `composer install --no-interaction --prefer-dist` | PASS desde lockfile, incluido package discovery; el intento inicial del sandbox falló al reemplazar cache y se repitió con permisos |
| `npm ci --ignore-scripts` raíz y `frontend/` | PASS desde ambos lockfiles; ambos reportaron cero alertas |
| `npm --prefix frontend run build` | PASS después de fijar versiones y activar strict/strictTemplates; bundle inicial 191.06 kB |
| `npm --prefix frontend test -- --watch=false` | PASS: un archivo, dos pruebas; ejecutado fuera del sandbox después de timeout del worker en el intento inicial |

Los artefactos locales son evidencia de trabajo; no están ligados a un SHA ni reemplazan required checks. [ESTADO_LOCAL_REPOSS.json](ESTADO_LOCAL_REPOSS.json) registra checks locales y estados de los 20 bloqueantes; no es un manifest de aceptación ni autoriza Sprint 1.

## Ejecutar las comprobaciones

Desde la raíz del proyecto, en PowerShell:

```powershell
./scripts/install-local-tools.ps1
. ./scripts/use-local-tools.ps1
php .tools/composer.phar install --no-interaction --prefer-dist
php .tools/composer.phar validate --strict
php .tools/composer.phar check-platform-reqs
php vendor/bin/phpunit
php .tools/composer.phar analyse
npm ci --ignore-scripts
npm run openapi:lint
npm run openapi:bundle
npm run build
npm ci --prefix frontend --ignore-scripts
npm --prefix frontend run build
npm --prefix frontend test -- --watch=false
```

Las instalaciones/audits requieren acceso a los registries oficiales y el build Vite requiere acceso a Bunny. La shell sólo se modifica durante la sesión con dot sourcing. `install-local-tools.ps1` es para Windows x64; requiere el Visual C++ runtime compatible que ya permitió ejecutar PHP en este host.

No ejecutar `composer run setup` sin resolver B04: el script original del skeleton incluye `artisan migrate --force`. No ejecutar el baseline SQL, ETL ni `migrate:fresh` contra una base persistente. Los tests aquí usan doubles para dependencias y no migran la base institucional.

## Readiness: comportamiento y límites

Live devuelve `200 {"status":"live"}` sin consultar DB, storage, Entra u OTLP. Ready consulta `SELECT 1`, escribe y limpia una clave aleatoria en el disk privado y exige un heartbeat entero UTC vigente en cache, bajo `modernization.outbox.last_success_at`. Devuelve `200 ready` o `503 unavailable` sin información interna.

TTL inicial configurable: `OUTBOX_HEARTBEAT_TTL=120` segundos, configuración de desarrollo pendiente de aceptación operativa. Un timestamp futuro, ausente o vencido produce 503. **No hay worker Outbox que emita este heartbeat todavía**; no se creó un comando para fingir disponibilidad. En el entorno incompleto se espera 503. Los doubles de pruebas acreditan conducta, no disponibilidad real de MySQL/storage/worker.

Faltan el worker real, el presupuesto/timeout efectivo de cada dependencia, fault injection representativo y validación de storage productivo. Por ello B15 sigue PARTIAL. El adapter actual no debe considerarse prueba de SLA ni protección frente a una dependencia que tarda indefinidamente.

## Reevaluación de bloqueantes

No se marca ningún bloqueante completo como VERIFIED sin evidencia del mismo SHA y aprobación/CI requerida.

| ID | Estado en el destino | Próximo cierre necesario |
|---|---|---|
| B01 | PARTIAL | Management validado; `/me`, cliente generado, breaking diff y checks del API pendientes |
| B02 | BLOCKED_INFO | Aprobar enum IAM, transiciones, login permitido y revocación; no inferir del DDL |
| B03 | READY_TO_FIX | Aprobar política y ownership; implementar Outbox/inbox/leases/retry/dead-letter/replay y suite concurrente MySQL |
| B04 | PARTIAL | Aprobar manifest tabla → módulo → sprint; resolver `users/password_reset_tokens/sessions` del skeleton antes de nuevas migrations |
| B05 | BLOCKED_INFO | Perfilado autorizado y política de correo mutable, duplicado, alias y reciclado frente a `(tid,oid)` |
| B06 | PARTIAL | Resolver los ocho AUTO_INCREMENT del baseline y IDs del skeleton; mantener excepciones sólo con ADR aprobado |
| B07 | BLOCKED_INFO | Origins, TLS, proxies, cookies/XSRF/CORS y callback exacto; Sanctum instalado no cierra este gate |
| B08 | BLOCKED_INFO | Tenant/app no productivos, referencias de credencial, claims redactados y usuarios de prueba |
| B09 | BLOCKED_INFO | Spike OIDC después de B07/B08/B11; sin ganador supuesto |
| B10 | BLOCKED_INFO | Storage productivo, antivirus, cuarentena y restore; no se instala un driver S3 sin elegir proveedor |
| B11 | BLOCKED_INFO | Secret store, ACL, owners, rotación/revocación; clave local no acredita infraestructura |
| B12 | BLOCKED_INFO | Collector/SDK OTLP, destino, redacción y política de fallo; no cerrado por instalar transitorios de herramientas |
| B13 | PARTIAL | Herramientas locales y audits pasan; faltan workflows/scans/SBOM/image scan/pins y required checks efectivos |
| B14 | BLOCKED_INFO | Versión MySQL objetivo, runtime/migrator separados, grants append-only y fresh/upgrade en DB efímera |
| B15 | PARTIAL | Live/ready y tests implementados; faltan worker/TTL aprobado/timeouts y fallos reales |
| BN-01 | PARTIAL | Laravel + Angular + contratos existen; faltan Compose/collector/CI y bootstrap completo desde clon |
| BN-02 | PARTIAL | Fuentes copiadas con hashes; siguen pendientes Git, aprobación y clon verificable |
| BN-03 | PARTIAL | Toolchain local instalado; faltan Docker/runner y paridad dev/CI/producción |
| BN-04 | BLOCKED_INFO | DDL físico, grants, volúmenes y perfilado legacy anonimizados, autorizados y con checksum |
| BN-05 | BLOCKED_INFO | Owners/RACI nominal, CODEOWNERS y branch rules verificadas |

## Secuencia concreta para continuar

1. Asignar autoridad de aprobación y decidir dónde versionar `servicios.reposs` y su copia de fuentes. Incorporar la adaptación raíz-backend al manifest de estructura; no crear una carpeta backend duplicada.
2. Aprobar IAM estados/correo/IDs y `MIGRATION_OWNERSHIP.md`. Mantener IAM en Sprint 1 y autorización/Organization en Sprint 2, conforme a la propuesta del plan. No traducir todo el baseline de una vez ni ejecutar baseline y migrations en paralelo.
3. Proveer runner Docker y MySQL objetivo efímero `servicios_moderno_test`, separar runtime/migrator y comprobar permisos. No usar el servidor MySQL local existente como sustituto sin decisión/autorización de DBA.
4. Implementar Shared técnico: command/query bus y transaction manager, Outbox/inbox confiable, auditoría, ports de storage/AV y telemetry. Probar concurrencia, recuperación, poison y replay en MySQL real antes de considerar B03/B14 cerrados.
5. Cerrar API desplegable y cliente Angular sólo con contratos aprobados. No anunciar módulos futuros ni incluir management en el cliente de negocio.
6. Obtener en paralelo topología, Entra, secrets, storage/AV, OTLP y baseline legacy. Preparar la infraestructura local y los pipelines con imágenes/actions pinneadas; validar políticas de seguridad y required checks en el repositorio real.
7. Ejecutar spike OIDC de Entry Gate y E2E de sesión/TLS cuando haya recursos no productivos acreditados. Automatizar `PRE_SPRINT_1_GATE` con evidencia vigente del mismo SHA; todos los B01–B15 y BN-01–BN-05 deben estar VERIFIED.

Hasta ese punto se permite avanzar en Sprint 0 y se conserva **NO-GO para Sprint 1**. Instalar dependencias no resuelve las decisiones funcionales, permisos ni servicios institucionales pendientes.

## Referencias de compatibilidad comprobadas

- [Laravel 13 y requisito mínimo PHP 8.3](https://laravel.com/framework/docs/releases).
- [PHP 8.4.26 Windows NTS y SHA-256 oficial](https://www.php.net/downloads.php?os=windows&version=8.4).
- [Matriz oficial Angular, Node, TypeScript y RxJS](https://angular.dev/reference/versions). El CLI instalado y sus requisitos `engines` son evidencia adicional para Angular 22.2.1.
