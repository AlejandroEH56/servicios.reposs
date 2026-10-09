# Ejecución del plan de cierres — 2026-10-08

Responsable: desarrollador actual / @AlejandroEH56. Evaluación local del working tree; los resultados anteriores al commit no se atribuyen al SHA de referencia `41e15f566813e7cbc640f7a3811e657d7c36a4ae`. El gate permanece NO-GO hasta completar el candidato y Entra real.

## Resultados comprobados

| Ensayo | Resultado | Artifact local |
|---|---|---|
| Laravel nativo | 69 pruebas, 302 aserciones, PASS | artifacts/phpunit-20261008.xml |
| MySQL objetivo | 28 pruebas ejecutadas, 327 aserciones, PASS; fallos optativos en su harness separado | artifacts/mysql-20261008.xml |
| Docker operativo | 8 pruebas, 93 aserciones, PASS | artifacts/docker-operational-20261008.xml |
| TLS Docker | 2 pruebas PASS, cadena/hostname verificados | tests/Browser/tls-stage.test.mjs |
| Chromium Docker | 5 pruebas PASS | artifacts/browser-docker.xml |
| Linux desde locks | 67 pruebas ejecutadas/281 aserciones PASS; 2 pruebas de perfiles nativos omitidas; PHPStan PASS | artifacts/linux-tests-20261008.log, artifacts/linux-phpstan-20261008.log |
| Angular | Cliente reproducible, build y 2 pruebas PASS | npm run api:check / build / test en frontend |
| Contratos | Lint API/management y breaking diff sin errores | artifacts/openapi-breaking-20261008.json |
| Gate sintético | 9 pruebas PASS | npm run test:gate |
| Formato/análisis | Pint y PHPStan nivel 6 PASS; scripts PS sin errores de parser; YAML/actions por SHA PASS | comandos documentados / workflows |
| Secrets | Historial y archivos publicables, sin hallazgos; reportes redactados | artifacts/gitleaks-history-20261008.json, artifacts/gitleaks-tree-20261008.json |
| SAST | 4 reglas versionadas, 56 archivos objetivo, 0 hallazgos y 0 errores | artifacts/semgrep-20261008.json |
| Audits | Composer y npm raíz/frontend sin advisories actuales | artifacts/*audit-20261008.json |
| Imagen seleccionada | PHP 8.4.26, Alpine 3.24, paquetes actualizados; Grype: 0 alta/crítica, 6 media. SBOM Syft/CycloneDX y provenance generados | artifacts/backend-alpine-*-20261008.json, artifacts/docker-build-alpine-20261008.log |
| Telemetry | 1.166 trazas con logs y exemplars correlacionados; 0 nombres sensibles revisados; caída real fail-open | artifacts/otel-validation-20261008.json |
| Restore | DB equivalente y archivos por ruta/tamaño/SHA-256; secretos íntegros; instancia sin red/puertos, detenida al terminar | artifacts/restore-20261008T151636Z.json |
| Rotaciones | DB anterior rechazada; nueva clave válida; APP_KEY previa recuperable mediante APP_PREVIOUS_KEYS | artifacts/rotations-20261008.json |
| Backup diario | Tarea Servicios.Modernizacion.BackupDesarrollo a las 02:30, perfil interactivo, StartWhenAvailable/WakeToRun | artifacts/backup-schedule-20261008.json |
| Gobernanza | main protegida, cinco checks obligatorios, sin force-push/eliminación; aceptación compatible con responsable único | artifacts/github-protection-readback-20261008.json |
| Preservación | 27/27 fuentes con SHA-256 original | docs/implementacion/SOURCES_MANIFEST.json |

El timeout inicial de Vitest se repitió de forma aislada y pasó. El target Linux corrigió permisos de cachés. Los scans Debian anteriores se conservan como FAIL; el resultado de Alpine no los sobrescribe. El SAST no equivale a una auditoría exhaustiva. Se analizaron las cinco imágenes: backend, proxy 2.11.7, ClamAV y collector sin alta/crítica; MySQL sin alta/crítica aplicable tras tres coincidencias FIPS clasificadas por paquete/versión exacta con vencimiento (ver TRIAGE_IMAGEN_MYSQL.md). Los informes originales FAIL se conservan. Proxy/AV/MySQL actualizados volvieron a pasar Docker 8/93, TLS 2 y Chromium 5.

## Cambios operables

- `iam:contact <IdentityId> --proof-file=<archivo privado>` exige perfil operator, prueba exacta tenant/objectId y reason code; admite contacto nulo, conserva unicidad, no fusiona cuentas y audita/invalida la autorización anterior. Microsoft Graph vuelve a ser la fuente del contacto al verificar un login posterior.
- `outbox:metrics --check` aplica umbrales de FAILED > 0, antigüedad elegible > 60 s y heartbeat > 120 s. `outbox:prune --limit=500` requiere operator: PUBLISHED 30 días e inbox 90 días, conserva FAILED y rechaza redelivery fuera de la ventana.
- `PrivateFileStore` usa cuarentena, MIME/hash y scanner real antes de promover. `/files/{ULID}` permite sólo al dueño activo con hash íntegro. El filesystem privado no publica rutas de storage. Los fixtures de restore carecen de datos personales; su identidad sin cuenta externa queda DESACTIVADA.
- OTLP sólo exporta campos permitidos y usa collector privado con dirección reservada; su indisponibilidad respeta el timeout y no modifica readiness. Diagnóstico interno SDK sin stack dumps; las señales operativas siguen exportándose.
- `scripts/backup-compose.ps1` cifra DB/storage/secretos con DPAPI CurrentUser y restaura en contenedor/volúmenes propios. Compara SQL normalizando exclusivamente charset explícito redundante de columnas; verifica inventario de contenido para no confundir formatos GNU/BusyBox tar.
- `scripts/rotate-compose-secrets.ps1` ensaya cambio de credencial y APP_KEY con reversión. Conserva hasta cuatro claves previas; su retiro requiere vigencia de sesiones y recuperación de datos acreditadas.

Los backups locales tienen límite de memoria de 256 MiB y dependen del perfil Windows. La tarea diaria requiere sesión del desarrollador y Docker disponible; no acredita SLA productivo ni copia entre hosts. El ensayo de restore observado fue aproximadamente 21 s, no una garantía de RTO para bases grandes. La disponibilidad productiva y su custodia de secretos se adecuarán al proyecto antes de publicar.

## Cierres externos y finales

1. **Entra:** secreto aceptado y lectura de App Registration HTTP 200; login real AADSTS50011. Graph rechazó modificar callbacks con HTTP 403. El responsable debe registrar ambas URL Web HTTPS exactas (8443 y 9443) y completar el spike allow/deny/retirada/rehabilitación. No se comparten tokens/MFA ni se añaden permisos administrativos al backend para resolverlo.
2. **Entra/secrets productivos:** rotación/revocación del secreto en administración autorizada y mecanismo de recuperación fuera del perfil Windows del ensayo.
3. **Candidato:** ejecutar y descargar CI del SHA limpio, vincular readback/evidencias y aceptación del responsable; evaluar los veinte bloqueantes. No convertir una preparación o un resultado de otro tree en VERIFIED.

[Plan restante](PLAN_CIERRES_PENDIENTES_PRE_SPRINT_1.md), [checklist](PRE_SPRINT_1_CHECKLIST.md) y [alcance aprobado de datos nuevos](adr/ADR-013-migracion-funcional-con-datos-nuevos.md).