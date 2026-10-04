# Diseño IAM con Microsoft Entra ID

## 1. Decisión de autenticación

La aplicación usa Entra ID para autenticar y IAM local para autorizar. Laravel es cliente OIDC confidencial y crea una sesión propia; Angular nunca recibe ni persiste access/refresh tokens de Entra.

| Alternativa | Resultado | Motivo |
|---|---|---|
| Sanctum SPA cookie | Elegida | Primera SPA same-site, cookie `HttpOnly`, sesión y CSRF de Laravel. |
| Passport | Rechazada | Convierte a Laravel en authorization server OAuth2, capacidad no requerida. |
| JWT aplicativo | Rechazada | Añade emisión, revocación y exposición en browser sin necesidad. |
| MSAL en Angular + bearer a API | No elegida inicialmente | Válida para SPA pública, pero expone access token al runtime JS y complica BFF/Graph; reconsiderar sólo si frontend y API no pueden compartir sitio. |

Sanctum no sustituye Entra: Entra completa OIDC; Sanctum protege la sesión creada por Laravel para las llamadas API de primera parte.

## 2. Registro Entra

Crear un App Registration de tipo Web por ambiente:

- tenant único institucional; no usar `common`;
- redirect URI exacta `https://<host>/auth/entra/callback`;
- front-channel logout URI si operación la requiere;
- secreto cliente sólo en secret store, con owner y rotación; preferir certificado si la plataforma lo soporta;
- scopes mínimos `openid profile email`; `offline_access` y Microsoft Graph sólo si un caso aprobado los necesita;
- no habilitar implicit grant;
- Conditional Access/MFA gobernado en Entra;
- app roles/grupos sirven como señal de provisioning, no reemplazan policies locales.

Variables requeridas, nunca versionadas:

```text
ENTRA_TENANT_ID
ENTRA_CLIENT_ID
ENTRA_CLIENT_SECRET o ENTRA_CLIENT_CERTIFICATE_*
ENTRA_REDIRECT_URI
ENTRA_ALLOWED_ISSUER
SESSION_DOMAIN
SANCTUM_STATEFUL_DOMAINS
FRONTEND_URL
```

## 3. Flujo login/callback/provisioning

```text
Angular            Laravel IAM                  Entra ID             MySQL/outbox
   | GET /auth/entra/login |                         |                     |
   |---------------------->| state+nonce+PKCE        |                     |
   |<----------------------| 302 /authorize          |                     |
   |----------------------------------------------->|                     |
   |<-----------------------------------------------| code                |
   | GET /auth/entra/callback?code&state            |                     |
   |---------------------->| POST /token             |                     |
   |                       |------------------------>|                     |
   |                       |<------------------------| ID token            |
   |                       | validate claims         |                     |
   |                       | transaction: upsert (tid,oid), account,       |
   |                       | provisioning state, audit, outbox ---------->|
   |<----------------------| rotate session + 302 frontend                |
   | GET /api/v1/me + cookie/XSRF                   |                     |
   |---------------------->|                         |                     |
```

Pasos obligatorios del callback:

1. comparar `state` en tiempo constante y consumirlo una sola vez;
2. intercambiar `code` desde backend y validar TLS;
3. validar firma/JWKS, `iss`, `aud`, `tid`, `exp`, `nbf`, `iat` y `nonce`; tolerancia de reloj acotada;
4. usar `(tid, oid)` como clave externa inmutable;
5. iniciar transacción: vincular cuenta, actualizar sólo atributos de perfil permitidos, evaluar provisioning, guardar auditoría/outbox;
6. regenerar ID de sesión y eliminar material temporal;
7. redirigir sólo a una URL local allowlisted, nunca al `returnUrl` arbitrario;
8. si la identidad está deshabilitada o no provisionable, no crear sesión autorizada.

## 4. Claims

| Claim | Uso | Regla |
|---|---|---|
| `tid` + `oid` | Clave de cuenta externa | Confiable sólo después de validar token; única en DB. |
| `iss`, `aud`, `exp`, `nbf`, `iat` | Validación token | Obligatorios según token; fallo cerrado. |
| `nonce` | Antireplay de login | Debe coincidir y consumirse. |
| `sub` | Sujeto OIDC por aplicación | Conservar para diagnóstico de protocolo; no sustituye `(tid,oid)`. |
| `name`, `given_name`, `family_name` | Presentación/provisioning | Mutables; sanitizar y no autorizar con ellos. |
| `preferred_username`, `email` | Contacto/display | Normalizar; nunca PK ni criterio de acceso. |
| `roles`/`groups` | Señal de mapeo | Allowlist explícita; roles IAM locales siguen siendo autoridad. |
| `jobTitle` | Perfil Organization | No es claim de autorización. Separar ` / ` en 0..n puestos mediante parser probado. |

Si se usan grupos, contemplar el overage de JWT; no interpretar ausencia de `groups` como “sin grupos” cuando existe claim distribuido. Preferir grupos asignados a la aplicación para reducir cardinalidad.

## 5. Provisioning

Estados de identidad: `PENDIENTE`, `ACTIVA`, `SUSPENDIDA`, `DESACTIVADA`.

- Primer login crea/vincula `iam_identidades` e `iam_cuentas_externas` en una transacción.
- `IdentidadInstitucionalVinculada` se guarda en outbox.
- Organization y Residencies consumen el evento idempotentemente para crear/vincular perfil cuando el tipo institucional esté confirmado.
- Si el perfil no puede inferirse, identidad queda `PENDIENTE`; un administrador resuelve sin duplicar por correo.
- Cambios de nombre/correo actualizan proyección; no cambian el ID.
- `jobTitle` vacío produce cero asignaciones; cada segmento válido produce posición ordenada. La sincronización finaliza asignaciones anteriores de origen Microsoft que ya no estén presentes, sin tocar asignaciones manuales.
- Deshabilitar identidad revoca sesiones y bloquea nuevos logins; los históricos conservan `IdentityId` lógico.

## 6. Sesión, CSRF, CORS y logout

- Cookie de sesión: `Secure`, `HttpOnly`, `SameSite=Lax`, path `/`, nombre por ambiente; duración ociosa 30 minutos y absoluta 8 horas como baseline a validar.
- Regenerar sesión al login y al elevar privilegios.
- Angular envía `withCredentials`; sólo origins exactos de ambientes aprobados en CORS.
- Mutaciones exigen CSRF. `GET` no cambia estado.
- Logout local invalida sesión y rota CSRF; logout federado es opcional y no debe aceptar redirect abierto.
- Cambio de rol crítico invalida sesiones o incrementa `authorizationVersion` comprobada por sesión.

## 7. Roles, permisos y policies

Roles iniciales son bundles administrables, no checks en código:

- `RESIDENTE`
- `DRPSS_REVISOR`
- `DRPSS_ADMIN`
- `DOCENTE_SOLICITANTE`
- `LABORATORISTA`
- `INVENTARIO_RESPONSABLE`
- `ADMIN_IAM`

Los permisos tienen namespace de módulo y verbo: `residencias.evidencias.revisar`, `laboratorios.solicitudes.resolver`, `inventarios.articulos.actualizar`. Una Policy combina:

```text
permiso efectivo
AND identidad activa
AND alcance (self/residente/laboratorio)
AND estado del agregado permite acción
AND, para acciones críticas, versión esperada
```

`Gate::before` sólo se permite para un rol break-glass controlado, temporal y auditado; no existe “superadmin silencioso”.

## 8. Pruebas de aceptación IAM

- state/nonce incorrectos, code repetido, issuer/tenant/audience no permitidos y token vencido fallan cerrados;
- session fixation no sobrevive al callback;
- correo cambiado conserva identidad `(tid,oid)`;
- identidad suspendida no obtiene sesión;
- grupo no allowlisted no concede rol;
- group overage sigue la ruta aprobada o deja provisioning pendiente;
- falta Graph no derriba login si Graph no es necesario;
- grants/revocaciones producen historial, auditoría y actualización efectiva;
- cada policy tiene pruebas allow/deny para ownership y alcance.

Fuentes: [Authorization Code + PKCE](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow), [claims de tokens](https://learn.microsoft.com/en-us/entra/identity-platform/access-token-claims-reference), [validación de claims](https://learn.microsoft.com/en-my/entra/identity-platform/claims-validation) y [Laravel Sanctum](https://laravel.com/framework/docs/12.x/sanctum).
