# ADR-006: Entra ID mediante OAuth 2.0/OIDC

Estado: Aceptada · Fecha: 2026-09-06

## Contexto

Entra ID autentica identidades institucionales; la aplicación mantiene perfiles y permisos propios.

## Problema

Evitar tokens en el navegador, provisioning multibase y uso de correo mutable como identidad.

## Opciones evaluadas

- JWT propio en `localStorage`: exposición ante XSS y ciclo de revocación propio.
- Passport como authorization server: innecesario; la aplicación no emite OAuth para terceros.
- OIDC backend + sesión Sanctum: credenciales fuera del JavaScript y CSRF integrado.

## Decisión

Usar Authorization Code OIDC contra tenant institucional, con `state`, `nonce` y PKCE cuando la librería lo soporte. Laravel actúa como cliente confidencial y establece sesión server-side `HttpOnly`, `Secure`, `SameSite=Lax`; Sanctum autentica la SPA same-site. No Passport ni JWT aplicativo. Vincular por `(tid, oid)`, validar issuer, audience, firma, tiempo y nonce; correo/nombre sólo son datos de presentación.

## Consecuencias

Angular y API deben compartir dominio superior y enviar credenciales/CSRF. Refresh/access tokens de Entra no se persisten salvo necesidad de Graph; cualquier persistencia será cifrada y rotada. Un fallo de provisioning no deja identidades parciales.

Fuentes: [Microsoft authorization code + PKCE](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow), [validación de claims](https://learn.microsoft.com/en-my/entra/identity-platform/claims-validation) y [Sanctum SPA](https://laravel.com/framework/docs/12.x/sanctum).
