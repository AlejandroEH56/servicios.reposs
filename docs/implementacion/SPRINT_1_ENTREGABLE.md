# Sprint 1 — Login Entra y sesión

Inicio: 2026-10-10, America/Mexico_City. Responsable: desarrollador actual. Base: `faf9ecc81e54f3367c1e74c8cdc3a430385729cc`, con Entry Gate pre-Sprint 1 GO. Se mantiene la migración funcional con datos nuevos y las decisiones IAM aceptadas.

## Slice implementado

El portal en `/portal/` consulta `/api/v1/me` y muestra login o shell autenticado. El login navega a `/auth/entra/login`; Laravel ejecuta OIDC, provisioning y rotación de sesión, y el callback lleva a `/dashboard` → `/portal/session` → `/portal/dashboard`. La página de sesión Angular consulta identidad; no recibe códigos ni tokens Microsoft.

Se conserva el callback Microsoft ya registrado mediante la configuración existente: `/auth/microsoft/callback` y `/auth/entra/callback` usan el mismo controlador. El alias de inicio `/auth/microsoft` permanece operativo. No se cambia la app registration ni se copia una credencial anterior.

`AuthFacade` usa el cliente generado para identidad, CSRF y logout, Signals para estado y RxJS para I/O. Deduplica consultas simultáneas, retira la identidad al vencer `sessionExpiresAt`, revalida al volver a la pestaña y descarta respuestas de identidad iniciadas antes de un logout exitoso. Una caída de servicio muestra reintento; un 401 lleva a login y un 403 a acceso denegado. No hay tokens en almacenamiento del navegador.

Los guards `canMatch`/`canActivate` revalidan la sesión y evitan navegación/descarga del shell sin acceso. La API sigue autorizando con `auth` y `RequireActiveIdentity`; los guards sólo controlan la experiencia del navegador, conforme a [Angular](https://angular.dev/guide/routing/route-guards).

Logout: GET `/sanctum/csrf-cookie`, seguido de POST `/auth/entra/logout` con `X-XSRF-TOKEN`. El endpoint canónico devuelve 204, invalida sesión y rota CSRF; errores usan Problem Details. `/auth/logout` conserva el comportamiento anterior de redirección para consumidores HTML. Es cierre local; no obliga a cerrar la sesión federada Microsoft.

`RequireCsrfToken` mantiene la política documentada de token por mutación. Laravel 13 admite por defecto Fetch Metadata `same-origin`; el middleware exige igualmente el token de sesión. Se conserva el cifrado/validación de cookies del framework y se prueban tokens ausentes/incorrectos y logout válido. Referencias: [CSRF Laravel 13](https://laravel.com/framework/docs/13.x/csrf), [XSRF Angular](https://angular.dev/api/common/http/withXsrfConfiguration).

## Persistencia y decisiones

El CI inicial detectó hallazgos High/Critical en Go 1.26.8 y x/net 0.58/0.59 dentro de proxy y collector. Se conservan los reportes FAIL. Caddy 2.11.7 se recompila con Go 1.26.9 (imagen por digest) y x/net 0.60.0; el collector mantiene componentes 0.161.0, pero se construye con OCB oficial y sólo OTLP, memory limiter, attributes/redact, batch y file export, con las mismas dependencias corregidas. Se conservan configuración, pipelines y usuario del contenedor. CI escanea las cinco imágenes construidas; no se agregan exclusiones de seguridad. El backup incorpora ahora también la imagen exacta del collector.

Fuentes: [correcciones Go](https://go.dev/doc/devel/release), [GO-2026-6612](https://pkg.go.dev/vuln/GO-2026-6612) y [builder oficial OpenTelemetry](https://opentelemetry.io/docs/collector/extend/ocb/). El uso de una distribución mínima requiere validar recepción, redacción y exportación de las tres señales; no se afirma compatibilidad con componentes ajenos a la configuración del proyecto.

No se agregan tablas ni se recrean las bases persistentes: `iam_identidades`, `iam_cuentas_externas`, sesiones y cache ya están implementadas. El provisioning existente mantiene vínculo exclusivo `(tenant, objectId)`, unicidad de correo y rechazo de colisiones, activación por grupo autorizado y bloqueo por suspensión/desactivación. Los catálogos y roles/permisos de Sprint 2 permanecen fuera del alcance.

## Criterios de salida y evidencias

| Criterio | Validación |
|---|---|
| Login/callback/provisioning y `/me` | `MicrosoftAuthenticationTest`, `EntraOidcTest`, `SprintOneSessionTest` |
| Suite negativa OIDC | State/nonce/PKCE/JWKS/claims/tiempo/replay del baseline y regresiones actuales |
| Fijación de sesión | Sesión DB previa destruida; cookie previa rechazada; nuevo ID de sesión |
| CSRF/logout | Token ausente o incorrecto rechazado incluso same-origin; token válido invalida sesión y rota CSRF |
| Angular | Tests de facade, guards y credenciales; componentes lazy; cliente generado reproducible |
| Navegador/TLS | `staging.spec.mjs`, `sprint-one-session.spec.mjs`, `tls-stage.test.mjs` |
| CI del candidato | backend, frontend-openapi, mysql, docker, security-contracts; exigir los cinco sobre el SHA actual |
| Shadow login real | Cuenta de ensayo aprobada: login → shell → logout → `/me` 401; confirmación del responsable |

Las pruebas UI positivas interceptan sólo las respuestas de sesión y logout en Playwright; prueban el comportamiento Angular y usan bootstrap CSRF del servidor real. No equivalen a una autenticación real Entra. El callback/rotación de sesión se prueban con provider sustituido en PHP, tanto SQLite como MySQL. Se conserva la evidencia real previa; la aceptación del nuevo shell requiere el shadow login anterior.

El responsable confirmó el 2026-10-10 el shadow login del nuevo shell con la cuenta de ensayo: aparece su sesión institucional y, al cerrar sesión, `/api/v1/me` devuelve 401. No se recopilaron tokens ni datos personales. El regreso al login también se verificó en las pruebas UI automatizadas.

Resultados de ejecución y estado de CI/shadow login se registran en `artifacts/SPRINT_1_VALIDACION_20261010.json` y `artifacts/SPRINT_1_RESULTADOS_20261010.md`, excluidos de Git. No reutilizar el dossier GO de la base para afirmar GO de otro SHA.

## Secuencia reproducible

1. Ejecutar `php vendor/bin/phpunit` y la suite aislada `php vendor/bin/phpunit -c phpunit.mysql.xml`.
2. Ejecutar Pint para PHP modificado y `composer analyse`.
3. Ejecutar `npm run openapi:lint`; en frontend, `npm run api:check`, `npm test -- --watch=false` y `npm run build`.
4. Construir backend/proxy/collector con Compose y recrear backend/worker/proxy/collector, conservando los volúmenes y credenciales existentes.
5. Ejecutar `npm run test:tls`, `npm run test:e2e` y `DockerOperationalTest` con CA de desarrollo confiable; verificar OTLP, redacción y exportación de trazas, logs y métricas. No deshabilitar TLS.
6. Versionar en rama de feature y abrir PR. Validar los cinco jobs del candidato y completar shadow login de la cuenta de ensayo.

Rollback funcional: reconstruir backend y frontend desde el candidato base conservando las bases y las correcciones de Go del runtime. No hay migración nueva que revertir. El respaldo cifrado de cierre pre-Sprint 1 sigue disponible para recuperación completa conforme al runbook portable; sus imágenes anteriores requieren aplicar nuevamente estas correcciones antes de habilitar el servicio.

No iniciar Sprint 2 antes de cumplir la salida dependiente y registrar el resultado real del nuevo slice.
