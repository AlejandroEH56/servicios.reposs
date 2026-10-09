# Checklist vigente de servicios.reposs

Evaluación: 2026-10-08, America/Mexico_City. HEAD de referencia: 41e15f566813e7cbc640f7a3811e657d7c36a4ae; cambios de trabajo sin versionar. **NO-GO**.

[Plan paso a paso](PLAN_CIERRES_PENDIENTES_PRE_SPRINT_1.md), [ambientes](AMBIENTES_MODERNIZACION.md), [responsable](RACI_CIERRES.md) y [resultados](VIABILIDAD_SERVICIOS_REPOSS.md).

El solicitante confirmó responsable, recursos y autorización de pruebas/recreación de desarrollo/herramientas. Se retiran los estados BLOCKED_INFO por decisiones institucionales pendientes; los cierres técnicos siguen PARTIAL. VERIFIED exige evidencia vigente del mismo SHA final y aprobación conforme al gate. La [copia histórica](historico/PRE_SPRINT_1_CHECKLIST_2026-09-27.md) permanece disponible.

| ID | Estado | Avance comprobado | Cierre aún necesario |
|---|---|---|---|
| B01 | PARTIAL | Contratos strict/bundles/cliente generado | Breaking diff y CI del candidato; ETag/If-Match al introducir mutaciones |
| B02 | PARTIAL | Estados, grupo activa, CLI auditada, authorizationVersion y vigencia de grupo 5 min | Acreditación real de retirada/archivo/rehabilitación y evidencia final |
| B03 | PARTIAL | Lease/concurrencia/inbox/retry/FAILED/replay CLI restringido y supervisor | Retención/métricas y fallos Docker implementados y probados; vincular evidencia al candidato |
| B04 | PARTIAL | Ownership definido; fresh/adopción pasan | Aceptación y evidencia del candidato, migraciones futuras en sus fases |
| B05 | PARTIAL | Correo único/colisiones/cambio/tenant-objectId probados | Contacto administrativo implementado y probado; alcance de datos nuevos aceptado |
| B06 | PARTIAL | Auditoría ULID y clasificación ADR-012 | Verificación de excepciones; conversión catálogo/FK en su fase |
| B07 | PARTIAL | Cinco pruebas Chromium en staging y cinco en ensayo production, TLS verificado, cookies/CSRF | Reproducir en topología Docker/CI y acreditar flujo Entra real |
| B08 | PARTIAL | Credenciales configuradas; metadata HTTPS y callbacks TLS de ensayo | App Registration/consentimiento/callbacks registrados y usuarios allow/deny |
| B09 | PARTIAL | PKCE/RS256/JWKS/claims y casos sintéticos PASS | Spike real redactado y aceptación del adapter |
| B10 | PARTIAL | Storage privado separado y perfil ClamAV preparado | Port/ClamAV/cuarentena/EICAR/fallo cerrado/restore comprobados; evidencia del candidato y recuperación productiva |
| B11 | PARTIAL | Credenciales ignoradas/ACL; runtime-migrator separados | DPAPI local, DB/APP_KEY y secret scan comprobados; falta Entra y recuperación fuera del perfil Windows |
| B12 | PARTIAL | Correlación y collector/redacción definidos | SDK, señales/exemplars correlados y fallo real acreditados; evidencia del SHA final |
| B13 | PARTIAL | Workflows base, pins, análisis/audits PASS | CI candidato, breaking/SAST/secret/SBOM/image/provenance y reglas remotas |
| B14 | PARTIAL | MySQL 26.7; fresh/upgrade/grants; principals persistentes de tres ambientes probados | Matriz Docker/CI y restauración sobre candidato |
| B15 | PARTIAL | Health, supervisor, fallos DB/storage PASS 2/35, worker 503 tras TTL y recuperación 200 | Repetición en Docker, métricas y recuperación con eventos representativos |
| BN-01 | PARTIAL | Laravel raíz/Angular/OpenAPI/Compose/configs | Servicios Docker activos; Linux desde locks probado; repetir CI candidato |
| BN-02 | PARTIAL | Fuentes preservadas y decisiones actuales redactadas | Versionar candidato y repetir evidencia sobre su SHA limpio |
| BN-03 | PARTIAL | Toolchain nativo, staging/production, WSL/Docker instalados; componentes habilitados con NoRestart | Reinicio/motor/runtime acreditados; Linux y suites Docker PASS; CI candidato |
| BN-04 | PARTIAL | Perfilado de configuración legacy autorizado y manifest | Funcionalidades con datos nuevos aceptado por el responsable (ADR-013); vincular decisión al candidato |
| BN-05 | PARTIAL | Responsable nominal/CODEOWNERS y permisos admin GitHub comprobados | Protección aplicada/readback mediante CLI del propietario; cinco checks y SHA candidato |

- [ ] Los veinte bloqueantes VERIFIED sobre el mismo SHA.
- [ ] Artifacts con fecha/comando/versión/ambiente/exit code/checksum.
- [ ] Required checks y branch rules efectivos comprobados.
- [ ] Gate devuelve GO y exit 0.

Los ambientes usan pruebas destructivas sólo en testing + servicios_moderno_test o SQLite :memory:. La autorización para recrear desarrollo no obliga a borrar la base existente; su identidad y datos se conservaron. El ensayo production local no es un despliegue público. No se usa la aprobación general para sustituir pruebas reales de proveedor o runtime. No se transfieren datos históricos por decisión expresa del responsable.

Resultado posterior al reinicio: [ejecución 2026-10-08](RESULTADOS_EJECUCION_2026-10-08.md). Los estados siguen PARTIAL hasta ligar evidencia vigente al SHA limpio y cerrar Entra real.
