# Resolución local de bloqueantes: servicios.reposs

Evaluación vigente: 2026-10-04. HEAD de referencia: `0546644746f1b2a281730a95c5fd8ef8bb48e2cc`. Cambios de esta implementación todavía en working tree: no hay evidencia de CI sobre su SHA final.

**Fundación de modernización viable y operativa en desarrollo. PRE_SPRINT_1_GATE: NO-GO.** Se completó la secuencia local de autenticación, adopción de esquema, outbox y validación. Los requisitos institucionales de TLS, operación, CI y datos legacy todavía impiden declarar cerrado todo el Entry Gate.

Las fuentes normativas siguen siendo PLAN_RESOLUCION_PRE_SPRINT_1.md e IAM_ENTRA_ID.md. El [checklist vigente](PRE_SPRINT_1_CHECKLIST.md) aplica esos criterios al Laravel actual. El [diagnóstico inicial](historico/VIABILIDAD_INICIAL_2026-10-04.md) y el [checklist histórico](historico/PRE_SPRINT_1_CHECKLIST_2026-09-27.md) se conservan para distinguir evidencias anteriores. SOURCES_MANIFEST.json usa snapshotPath para localizar la copia histórica del checklist original.

## Resultado implementado

- La pertenencia al grupo Microsoft autorizado activa identidades nuevas/PENDIENTE; SUSPENDIDA/DESACTIVADA se rechazan. Se conserva correo único y se rechazan colisiones sin fusionar identidades. El vínculo usa exclusivamente tenant/objectId. [Decisión IAM](decisiones/IAM_ESTADOS.md).
- El callback usa Authorization Code con PKCE S256, state consumible una vez, nonce y validación criptográfica RS256/JWKS, issuer, audience, tenant, oid y tiempos. Graph confirma grupo y el mismo objectId. Los errores/logs omiten credenciales, tokens y perfil.
- User representa iam_identidades con ULID; se eliminó la creación de usuarios/password del skeleton. Login rota sesión; logout POST invalida sesión y CSRF. /api/v1/me requiere ACTIVA, inactividad máxima 30 minutos y absoluta 8 horas; devuelve scopes/expiración y no entrega tokens.
- La base de desarrollo ya contenía el baseline y no tenía registros IAM/audit/outbox. Las migraciones adoptaron ese esquema, añadieron sessions/cache/jobs/inbox y lease/estado del outbox; auditoría vacía pasó de PK numérica a ULID. No se recrearon ni se vaciaron las tablas de negocio. [Ownership y límites](MIGRATION_OWNERSHIP.md).
- Se creó servicios_moderno_test con autorización explícita. Fresh/refresh/wipe están protegidos: sólo testing + esa base exacta o SQLite :memory:. Las migraciones de adopción requieren roll-forward.
- Outbox implementa claim SKIP LOCKED, lease/dueño, reclaim, retry/backoff, FAILED, error redactado e inbox transaccional. El consumidor implementado produce IDENTITY_LINKED. Replay interno se prueba, pero todavía no se expone como operación administrativa autorizada. [Política](OUTBOX_POLICY.md).
- Se declaró firebase/php-jwt como dependencia directa, conservando Graph SDK 3.7.0. Composer quedó válido sin advertencias de restricción exacta; no se cambiaron versiones instaladas. ng-openapi-gen 1.1.0 genera el cliente Angular de la API desplegada.
- Se configuró el CA bundle del Composer con checksum verificado para PHP portable: se corrigió cURL 60 sin desactivar TLS. La consulta pública de metadata del tenant configurado pasa por HTTPS; no inicia login ni valida el secreto/consentimiento de la App Registration.
- OpenAPI api.yaml contiene sólo /api/v1/me; management.yaml contiene health sin versión. Cliente Angular se regenera en un directorio de comparación y se verifica por hashes.
- Workflows locales tienen Actions fijadas por SHA oficial, PHP/Node/npm y herramientas fijadas, installs desde lockfiles, tests, análisis y audits. Son artefactos preparados: no se publicaron ni ejecutaron en GitHub; no equivalen a required checks aprobados.
- El gate automático exige los 20 IDs VERIFIED, evidencia vigente del mismo SHA, checks obligatorios, hashes de artifacts y working tree limpio. Sus pruebas rechazan falta de evidencia, estado parcial, SHA incorrecto, vencimiento, exit no cero, checksum incorrecto y check faltante.

## Evidencia ejecutada

| Comprobación | Resultado |
|---|---|
| PHPUnit base, IAM/OIDC, health/ProblemDetails y arquitectura | PASS: 50 pruebas, 180 aserciones |
| PHPUnit MySQL real, fresh/upgrade, dos conexiones, outbox, IAM y grants | PASS: 17 pruebas, 251 aserciones; MySQL 26.7.0 local |
| PHPStan nivel 6 / Pint dirty | PASS: cero errores / formato aplicado |
| Redocly recommended-strict API y management + bundle | PASS: sin errores ni warnings |
| Generación Angular y comparación de hashes | PASS; cliente tipado compila en strict |
| Angular build / tests | PASS / 2 pruebas |
| Gate unitario | PASS: 9 casos; gate real NO-GO esperado |
| Composer validate strict / platform | PASS |
| Composer audit y npm audit online, root y frontend | PASS: cero avisos de vulnerabilidad |
| Preflight OIDC público | PASS: metadata HTTPS verificada, tablas presentes, cero estados legacy |
| Smoke HTTP real localhost | live=200, ready=200 tras outbox --once, /me sin sesión=401 application/problem+json |

Runner completo: 15 comprobaciones PASS en artifacts/local-validation/manifest.json. Las suites finales, tras reforzar concurrencia y contrato /me, se repitieron en los XML siguientes.

Artifacts locales ignorados por Git: artifacts/phpunit-iam.xml, artifacts/mysql-iam.xml, artifacts/http-smoke.json. Los resultados validan working tree local; deben repetirse y adjuntarse en el commit final, con CI y approvals, antes de cambiar un requisito global a VERIFIED. Los grants probados pertenecen a cuentas temporales del test; las credenciales de la base persistente no se cambiaron.

## Secuencia operativa reproducible

Desde la raíz del proyecto, en Windows, usar el PHP portable directamente o cargar use-local-tools.ps1 en una sesión cuya política permita scripts. No cambiar la política global de Windows.

```powershell
& .\.tools\php\php.exe artisan modernization:preflight --oidc
& .\.tools\php\php.exe artisan modernization:prepare-test-database
& .\.tools\php\php.exe vendor/bin/phpunit -c phpunit.mysql.xml
& .\.tools\php\php.exe artisan migrate --force --no-interaction
& .\.tools\php\php.exe artisan outbox:work
```

migrate ya se aplicó en desarrollo y un segundo pase no tiene trabajo. Mantener outbox:work supervisado: --once fue sólo el smoke; al detener el worker, readiness debe pasar a 503 después de 120 segundos.

`powershell -NoProfile -ExecutionPolicy Bypass -File scripts/validate-pre-sprint1.ps1` habilita la política únicamente en ese proceso. Ejecuta la secuencia completa, conserva logs/checksums/contexto en artifacts/local-validation y termina con el gate. Necesita conectividad HTTPS para audits/metadata y acceso administrativo exclusivamente para crear la base/principals temporales de prueba. No ejecuta fresh sobre desarrollo. El exit 1 final por NO-GO es deliberado cuando faltan requisitos; un fallo de prueba detiene la secuencia antes.

## Cierres aún necesarios para avanzar de fase

1. Aprobar ownership/ADR de IDs y la política operativa de outbox, incluidos replay autorizado, retención y métricas. Completar transiciones administrativas y revocación por cambios de autorización/grupo durante sesión.
2. Acreditar topología TLS representativa y ejecutar navegador E2E de cookies Secure/HttpOnly/SameSite, XSRF, CORS y callback. La callback local configurada sigue HTTP loopback; no acredita B07.
3. Ejecutar catálogo de login real allow/deny en el tenant no productivo, documentar App Registration, callbacks/consentimiento y aprobar el spike OIDC. Metadata accesible no prueba que la credencial o el grupo sean correctos.
4. Configurar runtime/migrator reales con grants mínimos, append-only y MySQL objetivo; repetir en runner/Compose. Los ensayos locales en MySQL 26.7.0 no acreditan una versión productiva todavía no elegida.
5. Elegir/probar secret store y rotación/revocación, storage/AV/cuarentena/restore, OTLP/redacción/fallo collector. No se sustituyen esas decisiones instalando paquetes arbitrarios.
6. Obtener DDL/grants/perfilado legacy anonimizado y checksums; la base moderna vacía no es evidencia de calidad del legacy.
7. Nombrar owners/RACI/CODEOWNERS; ejecutar CI completa con SAST/secret/SBOM/image scans, breaking diff y contenedores pinneados; configurar y verificar branch rules/required checks.
8. Versionar el working tree, repetir toda la evidencia sobre el mismo SHA y ejecutar `node scripts/pre-sprint1-gate.mjs <manifest-CI>`. Sólo todos los obligatorios VERIFIED producen GO.

No se ejecutó login interactivo, publicación, push ni configuración administrativa de GitHub. El usuario autorizó continuar IAM pese a la secuencia histórica que situaba el spike antes del login; esto permite esta implementación local y no elimina los requisitos de promoción.

Referencias técnicas verificadas: [Microsoft OIDC + PKCE](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow), [Graph checkMemberGroups](https://learn.microsoft.com/en-us/graph/api/directoryobject-checkmembergroups?view=graph-rest-1.0), [ng-openapi-gen](https://github.com/cyclosproject/ng-openapi-gen), [MySQL 26.7.0 y su versionado](https://dev.mysql.com/doc/relnotes/mysql/26.7/en/news-26-7-0.html).
