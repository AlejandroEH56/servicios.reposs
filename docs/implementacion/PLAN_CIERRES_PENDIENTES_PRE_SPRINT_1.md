# Secuencia de cierre pre-Sprint 1

Actualizado: 2026-10-09, America/Mexico_City. Responsable @AlejandroEH56. El alcance es migrar funcionalidades con datos nuevos desde servicios.proyecto; no existe transferencia histórica pendiente. Ambientes y recursos se adecúan al proyecto por autorización del responsable.

Los cinco cierres técnicos que quedaban después de callbacks se ejecutaron. La decisión definitiva del Entry Gate exige las pruebas/CI y artifacts del commit limpio; este documento versionado describe los resultados y controles, no sustituye ese dossier.

| Orden | Cierre | Resultado y criterio |
|---|---|---|
| 1 | B02: ciclo IAM | Cuenta nueva de ensayo: grupo ACTIVA, retirada después de cinco minutos /me 401, restitución ACTIVA. SUSPENDIDA: sesión 401/login rechazado; DESACTIVADA: login rechazado; PENDIENTE: login nuevo ACTIVA. Versiones 2/3/4 y vínculo único corroborados por auditoría agregada. La reutilización de la versión anterior tras rehabilitación se acredita por regresión, no por una respuesta del usuario a ese paso |
| 2 | B06: frontera IDs | ADR-012 aceptado expresamente. Verificador read-only, framework/ledger admitidos, IDs desconocidos rechazados. Catálogos/FKs deben convertirse antes de habilitar su módulo; pruebas SQLite/MySQL fresh/adopción y aislamiento entre schemas |
| 3 | B09: adapter | Spike redactado, claims/algoritmos/JWKS/state/nonce/PKCE y límites temporales probados. Responsable aceptó el adapter REST + Firebase; SDK Graph queda para integraciones |
| 4 | B11: secretos | Sustituta Entra activada, login real confirmado, anterior retirada por responsable y rechazo OAuth corroborado. Recuperación age portable Windows→Linux, sin DPAPI; custodia externa confirmada por responsable |
| 5 | B10: recuperación | SQL/storage/AV/TLS/perfiles cifrados; fresh volúmenes/red, grants negativos, app HTTPS y worker recuperados. RPO 24 h/RTO 4 h; schedule 02:45 y política 14 días/30 copias/40 GiB. Fixture privado real escaneado y archivado forma parte de SQL/storage |
| 6 | Candidato | Pint/PHPStan, 80 pruebas backend y 33 MySQL ejecutadas pasan; once pruebas optativas MySQL omitidas se documentan. Versionar sólo código/docs/scripts, sin secretos ni artifacts privados |
| 7 | Verificación final | Reconstruir backend del candidato, recrear backend/worker, repetir pruebas Docker/TLS/fallos/restore y revocación Entra; proteger datos/cuentas habituales. CI push del mismo SHA y cinco contexts obligatorios |
| 8 | Entry Gate | Leer protección de main y resultados remotos. Generar `artifacts/PRE_SPRINT_1_EVALUACION_FINAL_20261009.json`, con comandos/versiones/fechas/SHA/hash; ejecutar gate. GO sólo con veinte VERIFIED y siete checks/evidencias válidos |

## Paso a paso reproducible

1. Leer decisiones aceptadas: IAM_ESTADOS, ADR-012, ADR-013 y SPIKE_OIDC_CIERRE_B09. No cambiar estados/política de correo ni habilitar catálogos numéricos.
2. Preparar perfiles privados con `scripts/prepare-compose.ps1`; comprobar Compose. Crear operadores con grants mínimos según el runbook; no usar el perfil administrativo en HTTP.
3. Ejecutar suites `phpunit`, `phpunit -c phpunit.mysql.xml`, PHPStan y Pint; activar pruebas opt-in sólo en los ambientes aislados indicados.
4. Versionar el candidato limpio y hacer push a la rama modernization. Esperar backend/frontend-openapi/mysql/docker/security-contracts; revisar artifacts SHA y scans, no sólo el color del workflow.
5. Construir y arrancar el candidato local; ejecutar DockerOperationalTest y ensayos TLS/Chromium. Revalidar hashes de código IAM sin atribuir al nuevo SHA ejecuciones antiguas.
6. Ejecutar respaldo portable Daily y Restore según [runbook](OPERACION_RECUPERACION_PORTABLE.md); conservar reporte de equivalencia y RTO. Confirmación externa de custodia se registra como tal, no como inspección de nube.
7. Generar dossier final y ejecutar `node scripts/pre-sprint1-gate.mjs artifacts/PRE_SPRINT_1_EVALUACION_FINAL_20261009.json`. Si falla, resolver su causa y repetir sólo lo afectado; no rebajar el gate ni reciclar evidencia de otro tree.
8. Con GO iniciar Sprint 1 según PLAN_EJECUCION. Antes de producción repetir recuperación en infraestructura destino y automatizar backups/custodia/alertas sin sesión interactiva.

La ejecución local de producción es un ensayo, no un despliegue público. Las fuentes normativas históricas se conservan; el dossier de artifacts es evidencia local de ejecución y no contiene valores secretos.