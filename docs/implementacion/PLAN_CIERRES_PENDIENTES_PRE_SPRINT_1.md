# Plan de cierres pendientes para iniciar la modernización

Actualizado: 2026-10-08, America/Mexico_City. Responsable: desarrollador actual / @AlejandroEH56.

Se ejecutó el plan después del reinicio. La migración será de funcionalidades con **datos nuevos**, según confirmación del responsable; no se requiere un dump histórico. Se conservan los datos existentes de desarrollo y las fuentes normativas. El Entry Gate continúa NO-GO mientras falten evidencia del candidato, CI completo y acreditación real de Entra.

## Secuencia y resultados

| Paso | Ejecutado y comprobado | Cierre restante |
|---|---|---|
| 1. HTTPS | Ensayos nativos staging/production: cinco pruebas Chromium y dos TLS por perfil. Docker: cinco Chromium y dos TLS, CA de usuario y hostname verificados | Repetir sobre candidato final y CI |
| 2. Contenedores | Reinicio acreditado; WSL y motor Docker disponibles. MySQL, backend, worker, proxy, ClamAV y collector arrancados; migraciones y grants aplicados | Linux limpio y CI del mismo SHA; gate de imágenes |
| 3. Outbox y fallos | DB/storage 503 con liveness 200 y recuperación; collector fail-open; heartbeat ausente y recuperación; reclaim, efectos/inbox sin duplicado, FAILED y replay auditado. Métricas/alertas y retención 30/90 días implementadas | Evidencia final; prueba temporal de TTL ya existe en ensayo nativo. No confundir heartbeat eliminado en Docker con esperar 120 s |
| 4. Entra real | Secreto aceptado; lectura de App Registration HTTP 200. Login real devolvió AADSTS50011; actualización de callbacks por Graph HTTP 403 | Agregar callbacks Web exactos y repetir login/MFA, allow/deny, retirada de grupo y rehabilitación |
| 5. IAM administrativo | Corrección de contacto por prueba tenant/objectId, correo único, colisión/nulo y auditoría; incremento de autorización sin reasignación. Estado y replay restringidos al perfil operator | Spike real de ciclo administrativo y aceptación del adapter |
| 6. Legacy | Alcance de funcionalidades/datos nuevos aceptado; ausencia de tablas históricas documentada | Convertir catálogos/FK al implementar cada módulo; no inventar resultados de datos históricos |
| 7. Secretos | ACL; backup DPAPI CurrentUser de DB/storage/secretos; rotación DB con rechazo de clave anterior y APP_KEY con recuperación por clave previa; secret scan del historial y fuente publicable sin hallazgos | Rotar Entra desde administración autorizada; definir recuperación fuera del perfil Windows y entrega productiva |
| 8. Storage/AV/restore | Port privado, cuarentena/hash/MIME/metadatos, scanner INSTREAM, promoción sólo limpia, acceso del dueño activo. Archivo limpio/EICAR/timeout/indisponibilidad reales; restore aislado comprobado | Repetir restore con fixture representativo y candidato; programar backups y comprobar retención/capacidad |
| 9. Observabilidad | SDK/export OTLP; 1.166 trazas con logs y exemplars de métricas correlacionados; cero nombres de campos sensibles revisados. Retención collector siete días; fallo real fail-open | Repetir sobre candidato; SDK interno sin stack dumps, logs de aplicación por allowlist |
| 10. Contratos/calidad/CI | Breaking diff contra HEAD sin errores; cliente Angular reproducible; SAST cuatro reglas/56 archivos sin hallazgos; audits Composer/npm corregidos. Workflows MySQL/Docker/SAST/secretos/SBOM/provenance preparados | Ejecutar workflows y scans del candidato; el scan de Debian anterior falló y no se usa como PASS |
| 11. Gobernanza | Protección de main aplicada y leída mediante CLI autenticada del propietario; checks backend/frontend-openapi/mysql/docker/security-contracts, sin force-push/eliminación; PR con cero aprobaciones obligatorias, compatible con responsable único | Guardar readback ligado al candidato; aceptación del release por el propietario |
| 12. Congelar/gate | Resultados y decisiones actualizados; pruebas sintéticas del gate pasan | Commit limpio, CI/artifacts del mismo SHA y evaluación final. No marcar VERIFIED con evidencia de otro tree |

## Paso a paso para los cierres que permanecen

1. En la App Registration `723fdec9-a641-40f0-a439-c8bc68baaa61`, abrir **Autenticación → Web**, conservar las URL existentes y agregar:
   - `https://localhost:8443/auth/microsoft/callback`
   - `https://localhost:9443/auth/microsoft/callback`
2. Guardar y comenzar un flujo nuevo desde `https://localhost:8443/auth/microsoft`; completar MFA en el navegador del responsable. No compartir códigos, contraseñas ni tokens. Registrar únicamente resultado/código de error.
3. Con una cuenta/grupo de ensayo controlados, probar miembro autorizado, fuera del grupo, cuenta suspendida/desactivada y retirada de grupo después de cinco minutos. La retirada no se hace sobre un grupo compartido sin una cuenta de ensayo identificada.
4. Rotar la credencial Entra mediante administración autorizada; actualizar sólo los archivos privados del ambiente y recargar backend/worker. Graph rechazó la escritura con el alcance actual; no habilitar permisos administrativos al backend como solución de login.
5. Ejecutar `scripts/backup-compose.ps1` y `scripts/backup-compose.ps1 -Action Restore -Snapshot <ID>` después de aplicar el candidato. El restore crea una instancia sin red/puertos y volúmenes propios; conserva el origen. Registrar RTO, antigüedad del snapshot, equivalencia SQL y hashes de archivos.
6. Conservar APP_PREVIOUS_KEYS hasta superar la vigencia de sesiones y recifrar datos representativos; retirar las claves previas con un ensayo de recuperación. El store DPAPI local requiere el mismo perfil Windows; no acredita recuperación entre hosts.
7. Construir desde lockfiles y ejecutar suites Linux, MySQL, TLS/navegador y fallos. Analizar cada imagen seleccionada; resolver o justificar individualmente los avisos con fuentes del proveedor. No eliminar severidades del gate para conseguir verde.
8. Revisar/versionar el candidato, activar CI mediante una rama de modernización y descargar los artifacts. La protección remota ya está activa; no repetir autorizaciones institucionales resueltas.
9. Generar un manifest de evaluación en `artifacts/` después del commit, con SHA, comando, versión, ambiente, exit code, fecha y SHA-256. El estado versionado es un resumen, no evidencia del SHA que lo contiene.
10. Ejecutar `node scripts/pre-sprint1-gate.mjs artifacts/<manifest-final>.json`. GO exige veinte bloqueantes y checks completos. NO-GO conserva los pendientes reales y permite continuar el trabajo independiente de desarrollo.

[Checklist vigente](PRE_SPRINT_1_CHECKLIST.md), [resultados de ejecución](RESULTADOS_EJECUCION_2026-10-08.md), [ambientes](AMBIENTES_MODERNIZACION.md) y [alcance de datos nuevos](adr/ADR-013-migracion-funcional-con-datos-nuevos.md).