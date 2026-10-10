Actualización vigente 2026-10-09: los cinco cierres técnicos restantes fueron ejecutados. Consulta [checklist vigente](PRE_SPRINT_1_CHECKLIST.md), [plan de cierre](PLAN_CIERRES_PENDIENTES_PRE_SPRINT_1.md) y [operación/recuperación](OPERACION_RECUPERACION_PORTABLE.md); el dossier final del commit limpio determina el Entry Gate. El contenido siguiente conserva el análisis histórico.

# Actualización posterior al reinicio — 2026-10-08

La implementación es viable y la fundación local ya opera en Docker con pruebas. El alcance es migrar funcionalidades con datos nuevos. [Resultados actuales](RESULTADOS_EJECUCION_2026-10-08.md) y [plan restante](PLAN_CIERRES_PENDIENTES_PRE_SPRINT_1.md) prevalecen sobre los pendientes históricos del análisis siguiente. El gate sigue NO-GO por acreditación real de Entra y evidencias del candidato.

# Viabilidad y resultados de servicios.reposs

Actualizado: 2026-10-05, America/Mexico_City. Referencia: HEAD 41e15f566813e7cbc640f7a3811e657d7c36a4ae, working tree modificado. **Fundación local operativa; Entry Gate NO-GO**.

La modernización es viable en el proyecto Laravel actual. El solicitante designó al desarrollador como responsable, autorizó pruebas/recreación de desarrollo/herramientas y confirmó que los ambientes se adecuarán al proyecto con los recursos del equipo actual. Se eliminan los pendientes de elección institucional; quedan implementación, pruebas y evidencia.

El trabajo restante se ordena en el [plan de cierres](PLAN_CIERRES_PENDIENTES_PRE_SPRINT_1.md). [Checklist](PRE_SPRINT_1_CHECKLIST.md), [ambientes/runbook](AMBIENTES_MODERNIZACION.md), [RACI](RACI_CIERRES.md), [perfilado legacy](PERFILADO_LEGACY_ANONIMIZADO.md).

## Cambios ejecutados

- Bases/perfiles separados de desarrollo, pruebas, staging y ensayo production. Se adoptó desarrollo sin fresh; staging/production tienen claves, credenciales, cache prefix y storage propios. Runtime por tabla sin DDL, auditoría SELECT/INSERT; migrator limitado a su esquema y sin CREATE USER.
- Microsoft sigue vinculando exclusivamente tenant/objectId; el grupo autorizado activa y las colisiones de correo se rechazan. El callback conserva PKCE S256, state/nonce y RS256/JWKS/claims. No se almacenan tokens del proveedor ni se envían al frontend.
- iam:state opera desde el perfil operator protegido por ACL, registra reason/actor técnico/transición y aumenta version_autorizacion. La rehabilitación vuelve a PENDIENTE; requiere nueva prueba de grupo para activar. Las sesiones anteriores se rechazan aun después de rehabilitar.
- Vigencia de comprobación de grupo: cinco minutos desde login, además de idle 30 min/absoluta 8 h. Al vencer se exige reautenticación; /me publica la expiración efectiva. No se afirma polling continuo del grupo.
- outbox:replay queda restringido al entorno operator y audita al operador sin asignar un GitHub handle a un FK de identidad. Worker nativo supervisado; heartbeat TTL 120 s.
- Caddy sirve Angular /portal y backend bajo el mismo origen HTTPS. TrustProxies se limita a loopback y a la IP fija del proxy Compose, sin confiar arbitrariamente en X-Forwarded-Host. Cookies host-only Secure/HttpOnly/Lax; CSRF negativo y positivo probados en Chromium.
- Dockerfiles/Compose preparados con imágenes fijadas por digest del registry, MySQL 26.7, PHP-FPM, worker, proxy/Angular, migrator/grants, ClamAV y collector. SQL con contraseñas se entrega como secret; el runtime no recibe credencial root.
- Caddy 2.11.7, Compose 5.6.0 y Playwright 1.63.0/Chromium instalados. Caddy se verificó con SHA-512 oficial y Compose con SHA-256. Se instalaron WSL 3.0.1 y Docker Desktop por usuario con instalador firmado por Docker Inc; cliente Docker 29.8.1 disponible. El motor aún requiere inicialización y componentes/virtualización del host.
- RACI/CODEOWNERS asignan al desarrollador actual. El conector confirmó la misma cuenta GitHub y admin sobre el repositorio; branch protection devolvió 403 Resource not accessible by integration.
- El perfilado read-only encontró sin tablas los cuatro esquemas legacy configurados. Código de servicios.proyecto disponible; no se acredita una población histórica que no está en este servidor.

## Pruebas y evidencia

| Comprobación | Resultado ejecutado |
|---|---|
| Suite Laravel, IAM/OIDC, outbox, health y arquitectura | PASS: 56 pruebas, 219 aserciones |
| Suite MySQL real fresh/upgrade/concurrencia/IAM/outbox/grants | PASS: 17 pruebas, 275 aserciones, MySQL 26.7.0; dos pruebas nativas opt-in omitidas en esta ejecución |
| Cuentas persistentes de desarrollo/staging/production | PASS: 2 pruebas, 21 aserciones; negativas DDL/auditoría/global accounts |
| Chromium staging | PASS: 5 pruebas; TLS verificado sin ignoreHTTPSErrors |
| Chromium ensayo production | PASS: 5 pruebas; CA y datos independientes |
| Smoke Node TLS staging/production | PASS: 2 pruebas por perfil, cadena/hostname/health/cookies/CSRF negativo/API 401 |
| Fallos DB/storage reales en staging nativo | PASS: 2 pruebas, 35 aserciones; readiness 503/liveness 200, redacción y recuperación |
| Worker detenido y recuperado | PASS: readiness 503 después del TTL de 120 s, recuperación a 200; dos pruebas TLS PASS después del reinicio del worker |
| Angular build / tests | PASS / 2 pruebas; tests repetidos fuera del sandbox tras bloqueo del worker |
| PHPStan nivel 6 / Pint | PASS: cero errores / formato aplicado |
| Redocly API y management | PASS: cero errores/warnings |
| Gate unitario | PASS: 9 casos |
| Composer validate strict / Composer audit | PASS / sin avisos |
| npm audit raíz/frontend | PASS: cero vulnerabilidades |
| OIDC metadata pública desde staging | PASS por HTTPS; no acredita secreto/consentimiento |
| Compose config --quiet | PASS estructural; no acredita imágenes construidas/servicios arrancados |
| Gate real | NO-GO esperado: estados parciales, cambios sin versionar y evidencia/checks finales pendientes |

Artifacts locales ignorados: artifacts/phpunit-closures.xml, mysql-closures.xml, browser-stage.xml, browser-production.xml, readiness-faults.xml, worker-recovery.xml y legacy-profile/manifest.json. No contienen dumps personales ni credenciales. La evidencia de esta tabla pertenece al working tree; debe repetirse sobre el candidato versionado. La base de desarrollo conserva sus datos existentes, incluido el IAM que se encontraba implementado.

El hardware tiene Windows 11 Pro, 12 procesadores lógicos, 15.7 GB RAM y 264.2 GB libres al inspeccionarlo. enable-container-host.ps1 se ejecutó con elevación y NoRestart; artifacts/environment/windows-container-features.json registra restartNeeded=true. Guardar el trabajo y reiniciar Windows, comprobar WSL y Server en docker version; si persiste el diagnóstico de virtualización, revisar firmware. No se reinició automáticamente el equipo.

## Cierres que siguen abiertos

1. Host Docker, build/arranque, clon limpio y matriz Linux/CI.
2. Entra real: callbacks registrados, consentimiento, allow/deny y retirada de grupo; corrección administrativa de contacto.
3. Origen físico legacy o decisión formal de ausencia de históricos; reconciliación/fixtures e IDs en sus fases.
4. Secret store/rotaciones/recuperación de APP_KEY, storage/AV/EICAR y backup/restore.
5. OTLP recibido/correlado/redactado y métricas/retención outbox; repetir fallos nativos ya comprobados en la topología Docker.
6. Breaking diff, scans/SBOM/provenance, CI y protección de rama con permisos del conector suficientes.
7. Versionar/revisar el candidato, evidencia del mismo SHA limpio y GO del gate.

Los informes y documentos de septiembre describen el punto de partida del legacy; no sustituyen este estado del Laravel. Se conservan las decisiones de dominio y las fuentes originales. La autoridad del desarrollador permite continuar los pasos; no se usa para declarar PASS sin ejecutar una prueba.
