# Spike OIDC y decisión de adapter para B09

Cierre ejecutado por encargo del responsable @AlejandroEH56. El responsable aceptó expresamente la decisión documentada junto con la frontera B06.

## Opciones comparadas

| Opción | Evidencia en este proyecto | Resolución propuesta |
|---|---|---|
| Adapter REST confidencial actual + Firebase PHP JWT 7.2.1 | Código, lockfile, suite negativa y Entra real; validación explícita del ID token antes de Graph | Seleccionar para la fundación IAM |
| Microsoft Graph SDK 3.7.0 / Kiota | Instalado para Graph; el canje anterior no acreditaba todos los controles de validación OIDC requeridos | Conservar para otras integraciones; no usar ese canje como autenticación sin un nuevo spike |
| Cliente OAuth genérico / Socialite con provider Microsoft | No tienen spike completo en este repositorio; requieren validar cómo se integra ID token, nonce/JWKS y vínculo tenant/oid | Alternativas futuras, sin atribuirles resultados que no se midieron |
| Passport o JWT aplicativo en Angular | Fuera del objetivo de ADR-006: no se necesita un authorization server propio ni exponer tokens Microsoft a la SPA | No seleccionados para esta arquitectura |

La selección se basa en cumplimiento demostrado en este proyecto. No se afirma que todos los candidatos hayan recibido el mismo ensayo ni que una biblioteca genérica sea incapaz de implementarlo. Se conserva el mantenimiento de las dependencias mediante locks/audits/CI; el código propio del protocolo y su matriz negativa quedan bajo responsabilidad del desarrollador.

## Matriz de aceptación

| Control | Evidencia y resultado |
|---|---|
| Code + PKCE S256 + state/nonce | EntraOidcTest y MicrosoftAuthenticationTest: PASS; redirect real 302 con S256 y callback HTTPS registrado |
| Firma RS256, rechazo HS256/firma alterada | EntraOidcTest: PASS con claves sintéticas; la ruta real usa el mismo validator |
| iss/aud/tid/oid/sub/nonce/azp y exp/nbf/iat | Matriz de claims inválidos PASS antes de Graph; tolerancia acotada a 60 s |
| Descubrimiento/JWKS HTTPS y renovación de kid | Host de claves restringido y refresh de kid desconocido PASS; HTTP/redirect a otro host rechazado |
| Replay y state malformado/caducado | State consumido una sola vez; límites 600 s y códigos 419/400; PASS |
| Identidad Graph y grupo | oid de Graph debe coincidir; mismatch/grupo vacío PASS sintético; allow y deny reales confirmados por el responsable y corroborados por auditoría agregada/log genérico |
| Provisioning atómico, correo único y estados | Tests de colisión/rollback, tenant/oid, suspensión y desactivación PASS; cuenta real de ensayo usada para ciclo administrativo |
| Sesión local, logout y expiración | Secure/HttpOnly/Lax; /me ACTIVA real; revocación por estado/versión y grupo a cinco minutos; PASS según pruebas y recorrido real |
| Material sensible | Tokens sólo en memoria del backend; no tokens en /me, outbox, logs o artifacts exportados; fixtures negativos sintéticos |
| Credencial Entra | Sustituta probada con OAuth y login real; revocación anterior se acredita por separado en entra-rotation.json |

Las evidencias reales son confirmaciones del responsable más consultas agregadas, no una captura de sus tokens ni una automatización de su MFA. El log de rechazo usa IAM_LOGIN_REJECTED y no demuestra por sí solo una razón Graph específica. Los controles criptográficos negativos usan claves sintéticas; no se provocan firmas manipuladas contra Microsoft.

## Decisión aceptada

Aceptar EntraOidcClient + IdTokenValidator como adapter de autenticación de esta fundación, manteniendo el SDK Graph para integraciones. Cambiar algoritmos, autoridad, persistencia de tokens o cliente exige repetir la matriz. Aprobación funcional/técnica: el responsable @AlejandroEH56 aceptó expresamente ambas decisiones B06/B09 durante esta ejecución.

Fuentes normativas preservadas: ADR-006 e IAM_ENTRA_ID.md. Implementación: app/Modules/IAM/Infrastructure/Entra, tests/Feature/EntraOidcTest.php, MicrosoftAuthenticationTest.php y OperationalIdentityTest.php. Referencias primarias: [Microsoft Authorization Code](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow), [Graph checkMemberGroups](https://learn.microsoft.com/en-us/graph/api/directoryobject-checkmembergroups?view=graph-rest-1.0), [Firebase PHP JWT](https://github.com/firebase/php-jwt). Evidencia versionada de código y CI se liga al candidato limpio; artifacts/ contiene el dossier de ejecución redactado.
