# Decisión IAM aplicada en desarrollo

Fecha: 2026-10-04. Autoridad funcional: respuestas explícitas del solicitante durante esta implementación. Pendiente incorporar owners institucionales y revisión sobre el commit final.

El grupo Microsoft autorizado activa automáticamente una identidad nueva o PENDIENTE. Sólo ACTIVA permite acceso. SUSPENDIDA y DESACTIVADA se rechazan incluso si Microsoft confirma pertenencia al grupo; la pertenencia no elimina una revocación local. La transición administrativa para suspender, desactivar, archivar o rehabilitar se implementará con permisos y auditoría en la fase IAM correspondiente. No existe un endpoint administrativo de rehabilitación en este cambio.

La identidad externa se busca exclusivamente por `(proveedor=entra, tid, oid)`. Nunca se vincula una cuenta existente por correo. El solicitante decidió conservar unicidad global del correo normalizado y rechazar colisiones. Un cambio de correo mantiene el mismo IdentityId; si colisiona, toda la transacción se revierte y el login se deniega. Un correo reciclado no autoriza reasignación del vínculo; requiere resolución administrativa. Correo ausente se almacena NULL; dos identidades con correo ausente no se fusionan.

Sesión local: inactividad máxima de 30 minutos, absoluta de 8 horas, regeneración al login y logout POST con invalidación y rotación CSRF. El estado local se comprueba en cada petición protegida. La pertenencia Microsoft se verifica en cada login; sincronización/revocación por retirada de grupo durante una sesión y cambios de authorizationVersion siguen pendientes. Angular nunca recibe tokens Microsoft; el callback consume state/nonce/verifier una sola vez.

El adapter usa Authorization Code + PKCE S256, state y nonce; Firebase JWT 7.2.1 valida RS256/JWKS y claims antes de consultar Graph. Las URLs de autoridad se construyen con el tenant configurado y las claves sólo se descargan de Microsoft por HTTPS verificado. El perfil Graph debe devolver el mismo oid validado. El grupo usa `/me/checkMemberGroups` con permiso delegado User.Read. El SDK Graph 3.7.0 instalado se conserva para otras integraciones; su canje original no entregaba una validación explícita de ID token, por lo que el login emplea el adapter REST probado. Esta selección técnica local aún requiere el spike institucional de B09.

Fuentes: [Microsoft Authorization Code flow](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow), [Graph checkMemberGroups](https://learn.microsoft.com/en-us/graph/api/directoryobject-checkmembergroups?view=graph-rest-1.0), [Firebase PHP JWT](https://github.com/firebase/php-jwt).

Evidencia reproducible: `tests/Feature/EntraOidcTest.php`, `MicrosoftAuthenticationTest.php`, `phpunit.mysql.xml`. Estas pruebas usan claves y datos sintéticos; no acreditan un login real en el tenant ni la expiración/rotación de la credencial configurada.
