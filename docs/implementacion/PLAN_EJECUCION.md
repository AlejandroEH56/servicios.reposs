# Plan de ejecución: vertical slices, datos y CI/CD

## 1. Cadencia y reglas

Baseline de planificación: sprints de dos semanas, una squad cross-functional de 5–7 personas con backend, frontend, QA y acceso parcial de DBA/seguridad/operación. El orden y los gates son vinculantes; el alcance temporal se reestima con capacidad real.

Cada sprint entrega un slice demostrable con API, UI, dominio, persistencia, autorización, auditoría/telemetría y pruebas. No se abre el siguiente módulo de alto riesgo si el criterio de salida dependiente no se cumple.

## 2. Plan de sprints

### Sprint 0 — Fundación reproducible

- Casos de uso: ninguno productivo; bootstrap, health, Problem Details, correlation ID, command/query bus, transaction manager, outbox, auditoría y storage ports.
- API: OpenAPI raíz, `/health/live`, `/health/ready`; middleware común.
- Angular: shell, layout, runtime config, error pages, cliente generado y design tokens mínimos.
- Migraciones: descomponer Shared (`compartido_*`, `migracion_*`) e IAM base desde el DDL; crear tablas operativas ETL.
- Infra/CI: PHP/Node/MySQL pin, Apache local, pipeline PR, secretos por ambiente, MySQL real en tests.
- Riesgos: versión Laravel/Angular no aprobada; falta de ambiente institucional, secret store, storage/AV y owner DBA.
- Salida: un commit limpio levanta todo desde cero; OpenAPI lint, tests y architecture tests pasan; ADR-002 resuelto.

### Sprint 1 — Login Entra y sesión

- Casos de uso: login, callback, logout, vincular/provisionar identidad pendiente y consultar identidad actual.
- API: rutas `/auth/entra/*`, `GET /api/v1/me`.
- Angular: inicio/callback, `AuthFacade`, shell autenticado, access denied, guards base.
- Migraciones: `iam_identidades`, `iam_cuentas_externas`, session/cache si se usa driver DB.
- Riesgos: app registration/redirects, claims reales, session domain, credenciales vencidas legacy; no copiar secretos anteriores.
- Salida: suite negativa OIDC, session fixation/CSRF y shadow login con usuarios de prueba aprobados.

### Sprint 2 — Autorización y Organization

- Casos de uso: roles/permisos, grant/revoke, perfil empleado, sincronizar `jobTitle`, puestos 0..n, grados y organigrama.
- API: `/iam/roles`, `/iam/permissions`, assignments; `/organization/employees|advisers|units`.
- Angular: administración IAM, empleado detalle, editor de puestos/grados y árbol organizacional.
- Migraciones: `iam_roles`, `iam_permisos`, relaciones; `organizacion_*`.
- Riesgos: equivalencia PHP-RBAC, overage de grupos, separación de puestos Microsoft/manual, ciclos de organigrama.
- Salida: matriz de permisos aprobada y pruebas allow/deny; reconciliación IAM/empleados piloto.

### Sprint 3 — Publicaciones, primer slice productivo

- Casos de uso: crear, editar, publicar, retirar y consultar avisos vigentes; adjuntar imagen segura.
- API: `/public/notices`, `/publications/notices`, staging/download de archivos.
- Angular: lista/detalle público y editor administrativo.
- Migraciones: `publicaciones_avisos`, `publicaciones_recursos_aviso`, metadata de storage necesaria.
- Riesgos: XSS almacenado, MIME/imagen maliciosa, vigencias y cache público.
- Salida: ruta strangler en producción para avisos, CSP/Trusted Types y rollback probado.

### Sprint 4 — Catálogos y semestres de planificación

- Casos de uso: mantener carreras, especialidades, asignaturas, retículas, grupos, laboratorios, semestres y días inhábiles.
- API: `/academic-planning/catalogs/*`, `/semesters`, `/laboratories`, `/holidays`.
- Angular: catálogos, lista/edición de semestre, laboratorio y días inhábiles.
- Migraciones: `planificacion_carreras`, `especialidades`, `asignaturas`, `reticulas`, `grupos`, `laboratorios`, `semestres`, tipos/días inhábiles.
- Riesgos: IDs/referencias legacy, más de un semestre activo, responsables Organization faltantes.
- Salida: catálogos y semestres reconciliados; una sola fuente de escritura definida.

### Sprint 5 — Horarios y disponibilidad

- Casos de uso: publicar horario, impedir solapamiento y calcular disponibilidad por laboratorio/intervalo.
- API: `/laboratories/{id}/schedules`, `/availability`.
- Angular: editor de horario y vista de disponibilidad.
- Migraciones: `planificacion_horarios`, índices de intervalo y read model de disponibilidad si las mediciones lo justifican.
- Riesgos: timezone, rangos de fechas, lock gaps, paridad con reglas legacy.
- Salida: golden master de disponibilidad y pruebas concurrentes contra MySQL.

### Sprint 6 — Residencia: perfil, empresa y proyecto

- Casos de uso: completar perfil, buscar/registrar empresa global, agregar contactos, crear proyecto y asignar asesores.
- API: `/residencies/me|residents|companies|projects` y asignaciones.
- Angular: dashboard, perfil, búsqueda/editor de empresa, proyecto y asesores.
- Migraciones: modalidades, programas, periodos, residentes, sectores, ramos, empresas/contactos, proyectos y asesores.
- Riesgos: merge de empresas/RFC, ownership, un proyecto por periodo, solapamiento histórico y snapshots de asesor.
- Salida: creación concurrente no viola unicidad/intervalos; expedientes piloto reconciliados.

### Sprint 7 — Expediente y revisión documental

- Casos de uso: definir requisitos, staging/upload, enviar/reemplazar evidencia, cola de revisión, aprobar/rechazar y consultar historial.
- API: `/files/staged`, `/residencies/files/*/requirements`, `/evidence`, `/review-queue`, `/reviews`.
- Angular: lista de requisitos, upload, historial, cola y formulario de revisión.
- Migraciones: definiciones, entregas y revisiones de evidencia; índices por alcance/estado/secuencia.
- Riesgos: malware, archivos huérfanos, autorización de expediente, dos revisiones con mismo número, migración de rutas.
- Salida: checksums y metadata 100% conciliados en muestra; AV y restauración probados; historial append-only.

### Sprint 8 — Reportes, liberación y emisión

- Casos de uso: plan 2/3+1, entregar reportes, resolver/reabrir liberación, emitir cartas/constancias con snapshot.
- API: `/projects/{id}/report-plan|reports|release|documents`, `/issued-documents/{id}`.
- Angular: plan de reportes, detalle/historial de liberación y documentos emitidos.
- Migraciones: reportes, liberaciones/historial, plantillas/recursos, documentos emitidos y constancias.
- Riesgos: matriz consulta–campo–plantilla incompleta, folios duplicados, render no equivalente, snapshot mutable.
- Salida: comparación visual/semántica aprobada por formato; hash/snapshot y reapertura concurrente probados.

### Sprint 9 — Solicitudes de laboratorio: captura

- Casos de uso: cotizar disponibilidad, crear/editar/enviar solicitud práctica/varia/extraordinaria y listar propias.
- API: `/laboratory-requests/availability-quotes`, collection/item, `/submit`.
- Angular: wizard discriminado, lista/detalle y calendario preliminar.
- Migraciones: `laboratorios_solicitudes` y tres detalles exclusivos.
- Riesgos: exactamente un subtipo, reglas temporales, falsa expectativa de reserva por cotización.
- Salida: schema discriminado y transición borrador→pendiente cubiertos; paridad de fixtures legacy.

### Sprint 10 — Decisión y calendario de solicitudes

- Casos de uso: cola, autorizar/rechazar/cancelar, evitar conflicto al decidir, comprobante y notificación.
- API: `/review-queue`, `/decisions`, `/cancel`, `/calendar`, `/receipt`.
- Angular: cola de revisión, decisión y calendario de ocupación.
- Migraciones: `laboratorios_decisiones_solicitud`, índices/constraints de estado; read model si medición lo requiere.
- Riesgos: doble autorización concurrente, permisos por laboratorio, PDF/correo repetido.
- Salida: stress concurrente sin solapamientos autorizados; efectos outbox idempotentes.

### Sprint 11 — Inventarios: raíz, ubicaciones e ítems

- Casos de uso: inicializar inventario, árbol de ubicaciones, registrar/editar/retirar cinco tipos de ítem y asignar acceso.
- API: `/inventories`, `/dashboard`, `/items`, `/locations` y permisos contextuales.
- Angular: selector, dashboard, lista/editor/detalle y árbol.
- Migraciones: inventarios, ubicaciones, artículos, cinco detalles, permisos de acceso.
- Riesgos: detalle/tipo incoherente, ciclos, número de serie duplicado, autorización cross-lab.
- Salida: exactamente un detalle por tipo en dominio/transacción; pruebas BOLA por laboratorio.

### Sprint 12 — Movimientos, incidencias, alertas y cierres

- Casos de uso: mover/ajustar, ledger, reportar/resolver incidencia, detectar alertas, ABC, cierre mensual y exportación.
- API: `/movements`, `/ledger`, `/incidents`, `/alerts`, `/abc-policy`, `/monthly-closes`, `/exports`.
- Angular: ledger, incidencias, alertas, cierre y exportaciones.
- Migraciones: movimientos, incidencias, políticas ABC, cortes/detalles y documentos laboratorio.
- Riesgos: stock negativo por carrera, cierre mutable, cálculo no reproducible, exports costosos.
- Salida: ledger y cantidades conciliados; cierre snapshot inmutable y job idempotente bajo carga.

### Sprint 13 — ETL integral, observación y cutover

- Casos de uso: ejecutar oleadas repetibles, reconciliar, shadow reads, congelar/cambiar owner de escrituras y retirar rutas migradas.
- API/UI: sin features nuevas; dashboard operacional de migración sólo si no expone PII; E2E completo por rol.
- Migraciones: índices afinados por `EXPLAIN`, tablas de reconciliación finales, sin cambio destructivo antes de ventana.
- Riesgos: datos legacy anómalos, duración de ventana, rollback inviable, diferencias de archivos/PDF y soporte vencido.
- Salida: dos ensayos de producción completos, reconciliación 100% o excepciones firmadas, rollback/restauración medidos, checklist go-live aprobado.

## 3. Estrategia ETL ejecutable

### 3.1 Tablas operativas de migración

Además de `migracion_mapa_ids`, las migraciones de Sprint 0 crean:

- `migracion_ejecuciones`: run ID, módulo, versión transformador, snapshot origen, inicio/fin/estado y conteos;
- `migracion_checkpoints`: run+módulo+partición, última clave ordenada y hash;
- `migracion_rechazos`: referencia legacy, código de razón, payload mínimo redactado y resolución;
- `migracion_reconciliaciones`: métrica, origen, destino, diferencia, evidencia y aprobación.

Son artefactos operativos con acceso restringido. No guardar filas completas con PII en rechazos.

### 3.2 Comandos Laravel

```text
php artisan legacy:inspect --snapshot=<id> --connection=<legacy_*>
php artisan data:migrate catalogs --run=<ulid> --batch=500 --resume --dry-run
php artisan data:migrate iam --run=<ulid> --batch=500 --resume
php artisan data:migrate organization --run=<ulid> --batch=500 --resume
php artisan data:migrate residencies --run=<ulid> --batch=250 --resume
php artisan data:migrate academic-planning --run=<ulid> --batch=500 --resume
php artisan data:migrate laboratory-requests --run=<ulid> --batch=250 --resume
php artisan data:migrate inventories --run=<ulid> --batch=250 --resume
php artisan data:reconcile <module> --run=<ulid> --format=json
php artisan data:reconcile all --run=<ulid> --fail-on-difference
php artisan data:cutover-status --module=<module>
```

Opciones comunes: `--dry-run`, `--resume`, `--batch`, `--max-errors`, `--source-snapshot`, `--from-key` para recuperación operativa. `--force` sólo se acepta en producción junto con confirmación de ambiente y run aprobado; nunca omite invariantes.

### 3.3 Pipeline por lote

```text
Extract SELECT-only con orden estable
 → normalize (trim/case/fechas/IDs, sin perder valor original trazable)
 → validate shape
 → resolve migracion_mapa_ids
 → construir Value Objects/agregado o DTO de importación gobernado
 → upsert idempotente por clave legacy
 → registrar mapa/checkpoint/conteos
 → reconciliar por agregado
```

- No offset pagination: usar keyset por PK legacy estable.
- Cada transformador tiene versión; reanudar con otra versión requiere run nuevo.
- Un hash lógico usa campos normalizados canónicos, no timestamps de migración ni nuevo ULID.
- Las anomalías van a rechazo con razón; no se inventan valores para hacer pasar `NOT NULL`.
- Archivos se copian a staging, verifican existencia/tamaño/SHA-256/AV, se promueven y después se enlazan.
- Durante backfill, legacy es owner. Para cada agregado se anuncia freeze, corre delta, reconcilia, cambia routing/write owner y observa.
- No dual-write independiente. Si el delta necesita captura, usar mecanismo explícito/outbox/CDC temporal y retirarlo tras corte.

### 3.4 Reconciliación y firma

| Módulo | Métricas obligatorias |
|---|---|
| IAM | identidades por `(tid,oid)`, roles/permisos efectivos, duplicados y estados. |
| Organization | empleados, cadena→puestos, posiciones vigentes, grados y unidades. |
| Residencies | expediente completo por residente, empresas mergeadas, proyectos por periodo, evidencias/revisiones/reportes/liberación. |
| Publications | avisos por estado/vigencia y checksum de recursos. |
| Planning | catálogos, semestre activo, horarios e intervalos. |
| Requests | solicitud+subtipo+última decisión, intervalos autorizados sin conflicto. |
| Inventories | artículos por tipo/lab, cantidades, ubicaciones, movimientos, incidencias y cierres. |
| Storage | cantidad, bytes, SHA-256, metadata, estado AV y huérfanos en ambos sentidos. |

Cada run produce JSON machine-readable y reporte firmado por owner de negocio + DBA. La diferencia permitida es cero salvo excepciones enumeradas con ID, causa, decisión y responsable.

### 3.5 Rollback

- Antes de ownership: descartar/recrear destino y repetir.
- Ventana: snapshot/PITR, legacy read-only, comandos nuevos registrados y reverse proxy reversible.
- Después de ownership: rollback de código sólo si schema es backward-compatible. Datos se recuperan por roll-forward o restauración + replay de outbox/comandos; no se intenta “desmigrar” con SQL improvisado.
- Criterios automáticos de abortar: error 5xx >2% por 5 minutos, discrepancia de integridad no aprobada, lag outbox >5 minutos, fallo storage/DB o autorización anómala crítica.

## 4. Git flow y CI/CD

### 4.1 Branching

Usar trunk-based development, no GitFlow clásico de ramas largas:

- `main`: protegida, siempre desplegable;
- `feat/<ticket>-<slug>`, `fix/...`, `chore/...`: vida menor a 3 días, PR obligatorio;
- `release` es un tag `vX.Y.Z` firmado, no una rama permanente;
- `hotfix/*` nace del tag productivo, vuelve por PR a `main` y genera patch tag;
- feature flags server-side para trabajo incompleto, con owner/fecha de retiro.

Requerir 1–2 revisores según CODEOWNERS; DBA revisa migraciones, seguridad revisa IAM/storage/autorización, frontend revisa contrato/UI. No merge commits manuales; squash y Conventional Commits para changelog.

### 4.2 Workflows GitHub Actions

`pull-request.yml`:

1. `changes`: detecta backend/frontend/docs/migrations.
2. `api-contract`: lint OpenAPI, ejemplos, breaking diff, genera cliente y comprueba árbol limpio.
3. `backend-static`: Composer validate, Pint check, PHPStan/Larastan máximo, architecture tests.
4. `backend-test`: MySQL 8 service, migración fresh, PHPUnit unit/integration/feature/contract en paralelo.
5. `frontend`: `npm ci`, format check, ESLint, unit/component, build producción y budget.
6. `security`: secret scan, dependency review, `composer audit`, `npm audit`, CodeQL/SAST y SBOM.
7. `e2e`: entorno efímero para PRs con labels de riesgo; obligatorio para IAM/API/UI/migrations.

`main.yml`: repite gates no confiando en resultados antiguos, construye una vez backend tar/container-equivalent y Angular static bundle, genera checksum/SBOM/provenance y publica artefacto por commit SHA.

`deploy.yml`: GitHub Environments `development`, `staging`, `production`; staging automático desde artefacto main y producción por tag/aprobación. Nunca recompila. Si infraestructura soporta OIDC desde Actions se evita secreto cloud duradero; para LAMP institucional usar SSH/deployer mínimo y host allowlisted hasta disponer de federación.

### 4.3 Quality gates

- cero fallo/warning de lint estático; baseline PHPStan sólo con deuda aprobada y decreciente;
- tests de invariantes/transiciones críticas completos; cobertura Domain/Application ≥80%; mutation testing selectivo en policies/reglas;
- OpenAPI sin breaking change no versionado y cliente generado reproducible;
- migraciones `migrate:fresh` en MySQL real y upgrade desde versión productiva ensayado;
- ninguna vulnerabilidad Critical/High explotable sin excepción temporal firmada; secrets bloquean merge;
- accesibilidad WCAG 2.2 AA en flujos críticos; bundle dentro de presupuesto;
- pruebas de concurrencia para reservas, proyecto-periodo, revisión, folio, stock y cierre;
- `EXPLAIN`/presupuesto de consultas para endpoints críticos; sin N+1 detectado;
- documentación ADR/runbook/changelog actualizada cuando el cambio altera operación.

### 4.4 Despliegue LAMP

```text
/var/www/servicios/
├── releases/<git-sha>/backend
├── releases/<git-sha>/frontend
├── shared/storage
├── shared/secrets-or-env-reference
└── current -> releases/<git-sha>
```

Orden: preflight → backup/PITR → migraciones expand backward-compatible con usuario migrator → smoke DB/storage → extraer artefacto → cache config/routes → cambio atómico de symlink/vhost → reload PHP-FPM/Apache y workers → smoke/E2E → observación. Los cambios contract destructivos ocurren en release posterior, tras retirar lectores legacy. Rollback de código cambia symlink; base usa roll-forward salvo runbook probado.

## 5. Checklist predesarrollo

- [ ] Aprobar ADRs y resolver excepción Laravel 12 vs Laravel 13.
- [ ] Confirmar Angular soportado, PHP minor, MySQL exacto y Node compatible.
- [ ] Nombrar owners técnico, negocio, seguridad, datos y operación por módulo.
- [ ] Registrar app Entra por ambiente; definir claims, MFA/Conditional Access y rotación.
- [ ] Rotar todas las credenciales legacy conocidas; confirmar que no estén en historial/artefactos.
- [ ] Obtener DDL autoritativo, tamaños, índices, grants y `information_schema` de cuatro bases.
- [ ] Verificar ausencia de consumidores SQL externos/triggers/procedures/views.
- [ ] Definir dominios, certificados, CORS/session domain y routing strangler.
- [ ] Elegir secret store, storage privado/AV, SMTP/Graph y observabilidad.
- [ ] Aprobar clasificación PII, cifrado, retención, RPO/RTO, backups y restauración.
- [ ] Aprobar matriz roles–permisos–alcances y cuentas break-glass.
- [ ] Aprobar OpenAPI conventions, Problem types y política de versionado.
- [ ] Preparar dumps anonimizados/fixtures y golden masters sin secretos.
- [ ] Asegurar disponibilidad de DBA, usuarios DRPSS, laboratoristas e inventarios para aceptación.

## 6. Checklist Sprint 0

- [ ] Backend y frontend nuevos separados del código CI4; versiones pinneadas.
- [ ] Providers y ocho módulos vacíos sólo donde haya código; architecture tests activos.
- [ ] MySQL 8 efímero y migraciones desde cero en local/CI.
- [ ] OpenAPI lint/breaking diff/generación Angular funcionando.
- [ ] Problem Details, correlation ID, idempotencia y optimistic concurrency definidos.
- [ ] Outbox+worker+dead-letter, auditoría y health con métricas de prueba.
- [ ] Storage staging/privado, límites y scanner simulado/real contratado.
- [ ] Secret scan, SAST, SCA, lockfiles, SBOM y protected branches/environments.
- [ ] CSP/Trusted Types baseline y cookies/CSRF/CORS probados.
- [ ] Conexiones legacy read-only y bloqueo técnico de escrituras verificado.
- [ ] Tablas/comandos ETL, checkpoints, rechazos y reconciliación con fixture piloto.
- [ ] Runbooks de deploy, rollback, restore, rotación de secretos e incidente iniciales.
- [ ] SLO/dashboards/alertas mínimos visibles para API, DB, storage, outbox y workers.

## 7. Checklist go-live

- [ ] Laravel y Angular siguen dentro de soporte; no existe excepción vencida.
- [ ] Dos ensayos de cutover completos con tiempos medidos y mismos artefactos/scripts.
- [ ] Backup/PITR y restauración DB+storage demostrados; RPO/RTO cumplidos.
- [ ] Reconciliación 100% o cada excepción firmada; checksums de archivos aprobados.
- [ ] Fuente única de escritura por agregado y legacy read-only durante ventana.
- [ ] Policies/roles/alcances comparados con matriz; pruebas BOLA/BFLA aprobadas.
- [ ] OIDC negativo, CSRF, sesiones, revocación y break-glass ensayados.
- [ ] Concurrencia de reservas, proyectos, revisiones, folios, stock y cierres aprobada.
- [ ] Pentest/DAST de staging y remediación de Critical/High; riesgo residual firmado.
- [ ] Performance/carga cumple SLO y capacidad de DB, PHP, workers y storage.
- [ ] CSP/headers/TLS/CORS/debug/grants/health revisados en configuración efectiva.
- [ ] Alertas y on-call probados; dashboards sin PII y reloj/NTP verificados.
- [ ] Migraciones expand aplicadas y rollback/roll-forward aprobado por DBA.
- [ ] DNS/vhost/certificado/routing, ventana y comunicación a usuarios preparados.
- [ ] Soporte, owners, escalamiento, runbooks y war room disponibles.
- [ ] Criterios de abortar y autoridad de decisión nominados antes de iniciar.
- [ ] Tras observación, rutas legacy retiradas, secretos temporales revocados y datos temporales eliminados de forma verificable.

## 8. Recomendación ejecutiva

Iniciar Sprint 0 sólo después de resolver el gate de versión y conseguir accesos no productivos de Entra/MySQL/storage. El primer slice productivo debe seguir siendo Publicaciones: valida el camino Angular→API→Policy→DB→storage→observabilidad con riesgo funcional acotado. No iniciar escrituras de Residencias, Solicitudes o Inventarios antes de demostrar outbox, autorización de objeto, locks y reconciliación en MySQL real.
