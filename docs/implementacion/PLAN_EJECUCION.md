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

Ejecución iniciada el 2026-10-19. La base del Sprint 2 ya está integrada en `main` mediante PR 5. El responsable aceptó `publicaciones.administrar` GLOBAL para DRPSS_ADMIN y ADMIN_PUBLICACIONES, con asignación explícita y auditada del nuevo rol a su cuenta habitual; el grupo Microsoft no concede estos permisos. Confirmó despliegue y ensayo local por ahora: no se declara completado el criterio de ruta productiva institucional.

El slice implementa `/api/v1/public/notices` y administración `/api/v1/publications/notices`, cursor cifrado ligado a filtros, If-Match y claves de idempotencia por actor/operación. Los avisos usan texto plano, enlaces HTTPS y vigencias UTC comprobadas en listado, detalle e imágenes. El tipo API RESIDENCIAS se traduce a RESIDENCIA del baseline; no se migran datos históricos. La adopción rechaza tablas de publicaciones pobladas que requieran conciliación.

Las imágenes requieren permiso, CSRF, MIME real JPEG/PNG/WebP, máximo 2 MB de entrada, 4096 píxeles por lado y cuatro millones de píxeles. Se escanean antes de decodificarse con GD y se reescriben como PNG, eliminando metadatos y payloads ajenos a los píxeles; el almacenamiento privado vuelve a escanear y verifica integridad. La vinculación sólo admite imágenes propias preparadas, no vencidas, limpias y de uso único. Cada descarga pública vuelve a comprobar el vínculo y la vigencia, sin caché. Las preparaciones sin vincular vencen a las 24 horas; no se activa destrucción automática de archivos.

El portal entrega HTML desde Laravel con nonce diferente por respuesta para scripts/estilos, CSP y Trusted Types en enforcement, y Angular aplica el nonce a estilos dinámicos. Caddy sirve assets y remite también `/portal/index.html` al controlador para impedir una entrada HTML sin política. Las rutas `/avisos` y `/reposs/avisos` dirigen al nuevo listado; `/reposs/bolsa-trabajo` conserva el filtro de empleo. El entorno de ensayo no tiene backend legacy activo ni datos históricos: no se promete una reversión a un escritor legacy.

`scripts/set-publications-route.ps1 -Action Disable|Enable` retira/restablece las lecturas públicas sin borrar avisos; al deshabilitar devuelve 404, conservando la administración. La reversión completa de versión usa el conjunto cifrado previo y la recuperación aislada. Runtime conserva DDL separado y no puede editar auditoría/historial. Los resultados concretos, candidato, checks y recuperación se registran en `artifacts/SPRINT_3_RESULTADOS_20261019.md` y `artifacts/SPRINT_3_VALIDACION_20261019.json` sin datos personales ni secretos.

### Sprint 4 — Catálogos y semestres de planificación

- Casos de uso: mantener carreras, especialidades, asignaturas, retículas, grupos, laboratorios, semestres y días inhábiles.
- API: `/academic-planning/catalogs/*`, `/semesters`, `/laboratories`, `/holidays`.
- Angular: catálogos, lista/edición de semestre, laboratorio y días inhábiles.
- Migraciones: `planificacion_carreras`, `especialidades`, `asignaturas`, `reticulas`, `grupos`, `laboratorios`, `semestres`, tipos/días inhábiles.
- Riesgos: IDs/referencias legacy, más de un semestre activo, responsables Organization faltantes.
- Salida: catálogos y semestres reconciliados; una sola fuente de escritura definida.

Ejecución del Sprint 4 iniciada el 2026-10-22 sobre el candidato validado del Sprint 3. El responsable aprobó ADMIN_PLANIFICACION con `planning.manage` y `planning.catalogs.manage` GLOBAL y la asignación explícita/auditada a su cuenta habitual. Todas las identidades institucionales activas pueden consultar; el grupo Microsoft y los puestos no conceden administración. Se mantiene el alcance de despliegue/ensayo local autorizado; el destino productivo no condiciona esta implementación.

AcademicPlanning es la única fuente de escritura de sus catálogos, semestres, laboratorios y días inhábiles en los ambientes modernos; no se activa sincronización ni un escritor legacy paralelo. El alcance es migración funcional con datos nuevos. La adopción verifica primero que el baseline Planning esté vacío; si contiene datos, rechaza la operación y requiere conciliación. El catálogo de tipos de día inhábil y su FK pasan a ULID antes de habilitar las rutas, conforme al ADR-012; no se altera el DDL histórico de referencia.

Las rutas son `/api/v1/academic-planning/catalogs/{catalog}` (`careers`, `specialties`, `subjects`, `curricula`, `groups`, `holiday-types`), `/semesters`, `/laboratories`, `/holidays` y `/responsibles`. El último selector exige `planning.manage` y expone sólo referencia/nombre de empleados activos de Organization. Se validan referencias activas, pertenencia especialidad–carrera, capacidad y fechas/intervalos; las horas de días inhábiles son RFC3339 y se guardan en UTC. La retícula sin especialidad conserva unicidad. No se borra físicamente; la desactivación de un padre con dependencias activas se rechaza.

Los semestres se crean PLANIFICADO. Transiciones explícitas: PLANIFICADO→ACTIVO, ACTIVO→CERRADO y PLANIFICADO/CERRADO→ARCHIVADO. Para activar otro se cierra antes el actual; no se sustituye automáticamente ni se reabre un cerrado/archivado. Una restricción única en la base y un bloqueo transaccional del escritor protegen la unicidad bajo concurrencia. PATCH recibe el formulario completo y exige If-Match; altas/transiciones exigen clave idempotente por actor/operación, con expiración de 24 h sin reutilización silenciosa. Cada cambio se audita y reautoriza en la transacción.

La UI `/portal/academic-planning` usa cliente generado OpenAPI, consulta paginada, edición con conflicto visible y selectores de referencias. Horarios y disponibilidad quedan para el Sprint 5. Los resultados del candidato y las pruebas se conservarán en `artifacts/SPRINT_4_RESULTADOS_20261022.md` y `artifacts/SPRINT_4_VALIDACION_20261022.json`; el cierre exige además el piloto real y la recuperación del conjunto definitivo.

El gate del 22 de octubre exigió actualizar el parche PHP a 8.4.27 en Windows/Compose/CI (archivo oficial verificado y digest OCI fijado), Vite a 8.3.3 en ambos árboles npm y CLI/build Angular a 22.2.4, manteniendo Angular runtime 22.2.1. El override Vite sustituye únicamente ese parche de la herramienta de build; no se usa `audit fix --force`. Los avisos aplicables de curl y ca-certificates requieren reconstrucción de paquetes. En x86_64, PHP instala únicamente curl/libcurl 8.23.0-r0 oficiales de Alpine, con firma y SHA256 verificados, sin agregar edge a los repositorios ni actualizar dependencias adicionales; el build comprueba la versión de libcurl cargada por PHP. El proxy elimina curl/libcurl, que no utiliza. Esta medida puntual se revisará cuando el canal estable incorpore el parche (CVE-2026-92392: https://curl.se/docs/CVE-2026-92392.html). MySQL conserva el motor 26.7.0: la actualización del sistema excluye los dos repositorios MySQL y la construcción comprueba la versión antes de publicar la imagen. La revisión exacta del nuevo cruce de canal OpenSSL/FIPS se documenta en TRIAGE_IMAGEN_MYSQL.md. La suite Linux creció a 153 casos y agotó el límite PHP de 128 MB; el target test recibe un presupuesto acotado de 256 MB.

### Sprint 5 — Horarios y disponibilidad

- Casos de uso: publicar horario, impedir solapamiento y calcular disponibilidad por laboratorio/intervalo.
- API: `/laboratories/{id}/schedules`, `/availability`.
- Angular: editor de horario y vista de disponibilidad.
- Migraciones: `planificacion_horarios`, índices de intervalo y read model de disponibilidad si las mediciones lo justifican.
- Riesgos: timezone, rangos de fechas, lock gaps, paridad con reglas legacy.
- Salida: golden master de disponibilidad y pruebas concurrentes contra MySQL.

Ejecución del Sprint 5 iniciada el 2026-10-23 desde `main` con las PR 7 y 8 integradas. Se conserva la matriz aprobada: ADMIN_PLANIFICACION usa `planning.manage` GLOBAL para publicar, editar, retirar y rehabilitar franjas; las identidades institucionales activas consultan. No se agregan roles ni asignaciones automáticas.

Las franjas son semanales, ISO lunes=1 a domingo=7, con precisión de minutos y zona `America/Mexico_City`. Se conserva el límite legacy 07:00–20:00 y las fechas inclusivas del semestre, tomadas de `MisReglas::horaNoRestringida`, `validarFechaConPeriodo` y `ConversionHoras`. Publicar/editar requiere laboratorio y semestre ACTIVO. Las franjas usan intervalos `[inicio, fin)`: se admiten adyacencias y se rechazan solapamientos activos; la retirada es lógica y exige If-Match, conservando versión y auditoría. Las altas requieren idempotencia de 24 h por actor/laboratorio. El bloqueo WRITER de Planning cubre también estos cambios, y la consulta toma bloqueo compartido durante su lectura consistente.

La API añade GET/POST `/api/v1/academic-planning/laboratories/{laboratory}/schedules`, PATCH `.../schedules/{schedule}` y GET `.../availability`. La disponibilidad intersecta las franjas publicadas del semestre activo con el intervalo consultado y resta los días inhábiles UTC globales o del laboratorio. Sin franja publicada no se presupone apertura. Se limita a 31 días, 200 franjas activas por laboratorio/semestre y 1000 intervalos inhábiles coincidentes; se falla de forma explícita al superar los límites, sin devolver disponibilidad parcial engañosa. La respuesta identifica su base `PLANNING_CALENDAR` y `reservation=false`; las ocupaciones/decisiones de solicitudes se incorporarán mediante sus ports en Sprints 9/10.

La migración adopta sólo `planificacion_horarios` vacío y conserva los catálogos/piloto del Sprint 4; añade versión, fecha de modificación e índice compuesto de consulta. AcademicPlanning continúa como único escritor. La pantalla `/portal/academic-planning/calendar` comparte el contrato y el cliente generado, conserva la clave al reintentar altas y recarga las referencias tras un conflicto de versión. La consulta explica que no reserva el laboratorio.

El golden master versionado está en `tests/Unit/availability-golden.json`: apertura legacy, ausencia de franjas, adyacencias, límites exactos, festivos parciales/solapados, fin de semestre, offsets históricos e intervalos con microsegundos. Dos procesos MySQL reales compiten por el mismo intervalo y repiten una publicación con la misma clave; sólo se admite una fila y un evento de auditoría. El cierre exige también piloto real, controles CI, medición de lectura para decidir sobre proyecciones y recuperación del conjunto definitivo. Evidencias: `artifacts/SPRINT_5_RESULTADOS_20261023.md` y `artifacts/SPRINT_5_VALIDACION_20261023.json`.

### Sprint 6 — Residencia: perfil, empresa y proyecto

- Casos de uso: completar perfil, buscar/registrar empresa global, agregar contactos, crear proyecto y asignar asesores.
- API: `/residencies/me|residents|companies|projects` y asignaciones.
- Angular: dashboard, perfil, búsqueda/editor de empresa, proyecto y asesores.
- Migraciones: modalidades, programas, periodos, residentes, sectores, ramos, empresas/contactos, proyectos y asesores.
- Riesgos: merge de empresas/RFC, ownership, un proyecto por periodo, solapamiento histórico y snapshots de asesor.
- Salida: creación concurrente no viola unicidad/intervalos; expedientes piloto reconciliados.

Ejecución del Sprint 6 iniciada el 2026-10-24 desde `main`, con la PR 9 del Sprint 5 integrada y su piloto, recuperación y custodia externa aceptados. El responsable aprobó RESIDENTE con permisos SELF para perfil, proyectos, asesores y empresas/contactos propios; DRPSS_REVISOR con consulta GLOBAL de expedientes; DRPSS_ADMIN y el nuevo ADMIN_RESIDENCIAS con administración GLOBAL. Se autoriza la asignación explícita y auditada de ADMIN_RESIDENCIAS a la cuenta habitual del responsable. El grupo Microsoft no concede roles de negocio. La inscripción de residentes es administrativa, vincula una identidad activa explícita y no asigna automáticamente RESIDENTE.

El slice usa `/api/v1/residencies/me`, `/residents`, `/companies`, contactos anidados, `/projects`, asignaciones internas/externas, `/programs`, `/periods` y `/catalogs/modalities|sectors|business-lines`. La UI es `/portal/residencies`. Identidad y número de control no se editan después de inscribir. Un residente consulta/edita únicamente su expediente y proyectos; los contactos personales de una empresa sólo se consultan por su creador autorizado, administración/revisión o residentes con proyecto en ella. Tener proyecto no concede escritura sobre una empresa ajena.

La empresa es global: RFC normalizado a mayúsculas, formato/dígito verificador, unicidad y colisión 409 sin fusión automática; RFC inmutable al editar. Se conserva Ñ mediante comparación binaria UTF-8. No se afirma validación registral fiscal ni se envían datos a servicios externos. La adopción rechaza cualquier tabla de Residencias poblada antes del DDL; convierte modalidades/sectores/ramos y sus FKs a ULID antes de activar las APIs, conserva los módulos y datos previos y añade versión/índices/grants mínimos.

Un proyecto por residente/periodo, reforzado por restricción única y bloqueo WRITER de Residencias. Las fechas son inclusivas, deben estar dentro del periodo ACTIVO y la vigencia del programa del residente; no se admiten días compartidos entre proyectos del mismo residente que no estén cancelados, incluso en periodos diferentes. El proyecto se crea BORRADOR y sólo se edita en ese estado con padres activos. Cambiar fechas/duración de un periodo usado se rechaza; se puede cerrar y archivar, sin reapertura. Los periodos de residencia son independientes de los semestres de Planning y pueden coexistir activos. El servidor deriva el plan 2/3 parciales más uno final de la duración 4/5/6 meses, sin abrir entregas en este sprint.

POST usa idempotencia de 24 h por actor/operación/padre, reautorizada en reintentos; PATCH exige If-Match. Asignar/finalizar asesor exige además la versión del proyecto y la incrementa. Instantáneas de nombre/puesto/grado se obtienen de Organization o del contacto de la empresa del proyecto; no se aceptan del cliente ni se reescriben al actualizar el origen. Se admite un principal interno y otro externo por intervalo, sin duplicar al mismo asesor en fechas inclusivas solapadas. Se conserva historial y sólo se cierra una asignación abierta. Organization consulta candidatos por lotes; la asignación revalida empleado activo/habilitado. Las lecturas toman bloqueo compartido para mantener coherencia entre versión, referencias e historial; los escritores se serializan, con coste de contención a reevaluar bajo carga representativa.

El criterio de salida exige dos procesos MySQL reales para unicidad residente/periodo, solapamiento entre periodos, reintentos simultáneos y colisión global de RFC, más conciliación del expediente piloto. Se mantiene el despliegue/ensayo local autorizado, sin importación histórica. Evidencias y cierre: `artifacts/SPRINT_6_RESULTADOS_20261024.md` y `artifacts/SPRINT_6_VALIDACION_20261024.json`. Evidencias documentales, reportes, liberaciones y emisión se conservan para Sprints 7/8.

La inscripción permite vincular también la identidad activa de administración, sin restricción por dominio de correo. Ante validación fallida, el formulario conserva los datos e indica los campos que requieren corrección; Problem Details sólo devuelve nombres de campos declarados, sin valores ni mensajes sensibles. El piloto permanece pendiente hasta confirmar el alta real y los demás pasos.

### Sprint 7 — Expediente y revisión documental

- Casos de uso: definir requisitos, staging/upload, enviar/reemplazar evidencia, cola de revisión, aprobar/rechazar y consultar historial.
- API: `/files/staged`, `/residencies/files/*/requirements`, `/evidence`, `/review-queue`, `/reviews`.
- Angular: lista de requisitos, upload, historial, cola y formulario de revisión.
- Migraciones: definiciones, entregas y revisiones de evidencia; índices por alcance/estado/secuencia.
- Riesgos: malware, archivos huérfanos, autorización de expediente, dos revisiones con mismo número, migración de rutas.
- Salida: checksums y metadata 100% conciliados en muestra; AV y restauración probados; historial append-only.

Ejecución del Sprint 7 iniciada el 2026-10-24 desde `main`, con la PR 10 del Sprint 6 integrada, su piloto conciliado y su respaldo/restauración/custodia aceptados. El responsable aprobó `residencies.requirements.manage` GLOBAL para ADMIN_RESIDENCIAS/DRPSS_ADMIN; `residencies.evidence.submit` SELF para RESIDENTE y GLOBAL para administración; `residencies.evidence.review` GLOBAL para DRPSS_REVISOR y administración. Se autoriza una asignación explícita y auditada de RESIDENTE a la identidad de ensayo elegida en Sprint 6. No se conceden roles por grupo Microsoft ni por inscripción, ni se permite revisar el propio expediente o un archivo cargado por la identidad revisora.

El portal `/portal/residencies/evidence` separa expediente, definiciones y cola de revisión. La preparación usa `/api/v1/residencies/files/staged`, con finalidad RESIDENCY_EVIDENCE: la ruta `/api/v1/files/staged` y su contrato de Publicaciones permanecen intactos. Esta precisión evita ampliar incompatiblemente la respuesta publicada; no se añade ninguna excepción al gate OpenAPI. El cliente generado usa OpenAPI 0.7.0. Requisitos de alcance RESIDENTE o PROYECTO, opcionales por programa, vigencia inclusiva, orden, tipos permitidos y tamaño máximo de 10 MB; este sprint sólo habilita PRERREQUISITO/REQUISITO de tipo DOCUMENTO. Los reportes y la liberación continúan en Sprint 8.

PDF, JPEG, PNG y texto plano se validan por MIME real y límites del requisito, con claves opacas y antivirus cerrado ante fallos. Se reserva la clave de carga antes de escanear, fuera del bloqueo transaccional largo; la preparación queda ligada a actor, residente, requisito y proyecto opcional, vence en 24 h y se consume una vez. Límite de 100 preparaciones por actor en 24 h y 10/min. Un fallo o infección conserva metadata/cuarentena privada; no se borra automáticamente ningún documento ni historial. El envío vuelve a verificar versión del requisito, vínculo, SHA-256, MIME y tamaño del binario. Se conserva cada versión y su instantánea de requisito; un rechazo o solicitud de cambios permite reemplazarla, una aprobación no se reemplaza sin el proceso posterior de reapertura.

Las mutaciones usan el bloqueo WRITER de Residencies y los POST se deduplican por actor/operación durante 24 h; el envío exige el consecutivo documental anterior y la revisión el siguiente número de revisión. Sólo ENVIADO admite una decisión; CAMBIOS_SOLICITADOS conserva esa decisión en historial y materializa RECHAZADO. Rechazo/cambios requieren observaciones. Tras el primer envío quedan fijos programa, código, etapa, alcance, vigencias y límites de la definición; el perfil tampoco cambia de programa si tiene documentos. Los envíos en un proyecto requieren BORRADOR y periodo ACTIVO; el cierre conserva consulta, descarga e historial. Descarga siempre autorizada, como adjunto y sin caché, tras verificar integridad; sin rutas públicas ni nombres de filesystem del cliente.

La adopción rechaza tablas documentales pobladas antes del DDL y conserva los padres del Sprint 6. Entregas y preparaciones tienen SELECT/INSERT/UPDATE sin DELETE para runtime; revisiones sólo SELECT/INSERT y sin acceso para el operador IAM. El historial se inserta con agregado/auditoría en una sola transacción. Se prueban dos procesos MySQL reales para preparación concurrente, envíos/reintentos, revisiones/reintentos y revisión contra reemplazo. El cierre exige piloto con residente y revisor separados, conciliación de metadata/binarios, AV, imágenes exactas y restauración aislada; no se considera cerrado mientras falten esas evidencias.

### Sprint 8 — Reportes, liberación y emisión

- Casos de uso: plan 2/3+1, entregar reportes, resolver/reabrir liberación, emitir cartas/constancias con snapshot.
- API: `/projects/{id}/report-plan|reports|release|documents`, `/issued-documents/{id}`.
- Angular: plan de reportes, detalle/historial de liberación y documentos emitidos.
- Migraciones: reportes, liberaciones/historial, plantillas/recursos, documentos emitidos y constancias.
- Riesgos: matriz consulta–campo–plantilla incompleta, folios duplicados, render no equivalente, snapshot mutable.
- Salida: comparación visual/semántica aprobada por formato; hash/snapshot y reapertura concurrente probados.

Ejecución iniciada el 2026-10-28 desde `main` `b1eb82e6cc8f1bad7cd92870e01079fb4bdd4a87`, con el Sprint 7 aceptado y su custodia confirmada. El responsable aprobó ampliar los roles existentes: RESIDENTE entrega reportes propios; DRPSS_REVISOR revisa reportes y reabre revisiones; ADMIN_RESIDENCIAS/DRPSS_ADMIN también administran plantillas, emiten/anulan documentos y resuelven/reabren liberaciones. No se autorizan asignaciones adicionales ni autorrevisión o decisiones sobre el propio expediente. El 2026-10-29 aceptó agrupar constancias por asesor y programa del proyecto seleccionado, con proyectos liberados del mismo semestre y consulta GLOBAL; se conserva el folio anual/semestral del legado. Esto sustituye la adscripción académica del asesor, todavía ausente en Organization.

La implementación utiliza `openapi/completion.yaml` 0.8.0 y `/portal/residencies/completion`. El detalle de ventanas inclusivas, calificaciones, dependencias de revisiones, permisos y matriz consulta–campo–plantilla está en `API_REST.md`. La instantánea y el HTML de emisión son inmutables; la cola PDF tiene un worker dedicado. Los grants del runtime permiten únicamente las columnas operativas de entregas, reportes, liberaciones, plantillas y emisiones; los historiales y anulaciones sólo admiten SELECT/INSERT. El operador IAM no recibe acceso documental. La copia cifrada previa al despliegue `20261029T064852Z` conserva los datos e imágenes aceptados del Sprint 7. El sprint continúa EN EJECUCIÓN hasta completar regresión, concurrencia MySQL, comparación por formato aceptada, piloto, CI, imágenes y restauración/custodia definitivas. Producción permanece diferida conforme al alcance aprobado.

El 2026-10-29 el responsable aprobó expresamente los seis PDF sintéticos de caracterización. La aceptación está ligada a los hashes de sus seis vistas y del renderizador; el reporte final conserva todas las palabras del legado y ajusta sólo el pie/espaciado para evitar una hoja adicional. La regresión Linux del candidato pasó 276 pruebas y 1,779 aserciones, con Larastan sin errores; el portal pasó 56 pruebas unitarias. La copia previa se renovó como `20261030T052900Z` y pasó la restauración aislada de SQL, archivos y servicios. La reapertura de evidencia ordinaria también se ofrece en el portal, conserva revisiones y respeta la liberación aprobada. Esta aceptación visual no sustituye el piloto funcional ni la recuperación/custodia definitivas todavía pendientes.

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
