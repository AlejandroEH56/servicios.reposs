# Sprint 2 — Autorización y Organization

Inicio: 2026-10-10, America/Mexico_City. Responsable: desarrollador actual. Base validada de Sprint 1: `8a3726ff1ba67404db01022850a87dd517e92e66`. Migración funcional con datos nuevos conforme a ADR-013. Las ramas de los sprints se revisan en secuencia; el trabajo no presupone su integración en `main`.

## Decisiones aceptadas por el responsable

El responsable aceptó expresamente la matriz inicial y designó su cuenta institucional habitual de desarrollo como primer administrador IAM y Organization. El bootstrap operativo asigna sólo esa identidad, requiere vínculo Entra y estado ACTIVA, registra operador/matriz y falla si ya existe un administrador permanente. No modifica los roles de la cuenta de ensayo.

| Rol | Capacidades del Sprint 2 |
|---|---|
| ADMIN_IAM | Consultar/administrar roles, permisos y asignaciones GLOBAL |
| ADMIN_ORGANIZACION | Empleados, puestos manuales, grados, niveles y unidades GLOBAL |
| EMPLEADO, DOCENTE_SOLICITANTE, LABORATORISTA, INVENTARIO_RESPONSABLE | Consultar empleado propio y gestionar grados propios SELF; consultar niveles |
| DRPSS_REVISOR, DRPSS_ADMIN | Perfil y grados propios; directorio mínimo de asesores GLOBAL |
| RESIDENTE | Sin permisos Organization en este sprint |

La pertenencia al grupo Microsoft permite entrar y activar la cuenta; no asigna roles ni habilita asesoría. `jobTitle` tampoco concede permisos. El catálogo ejecutable aprobado está en `config/authorization.php`. Un rol personalizado nace sin permisos. Los permisos de módulos futuros presentes en el SQL baseline se conservan, pero no se exponen como otorgables ni autorizan módulos todavía no habilitados.

## Slice implementado

IAM usa ports de Application, persistencia modular, Gates/policies y recursos API versionados. `/api/v1/me` devuelve roles, permisos y alcances efectivos de asignaciones vigentes. Las mutaciones exigen CSRF y versiones esperadas; incrementar `version_autorizacion` impide reutilizar sesiones anteriores. El permiso SELF sólo coincide con la identidad propietaria; omitir el propietario no lo transforma en GLOBAL.

El mutex RBAC serializa grant/revoke y edición de permisos en MySQL. Se protege el último administrador permanente ACTIVA con permiso GLOBAL `iam.roles.gestionar`, incluso al editar el conjunto de permisos. No se permite vencer una asignación que administra IAM. El historial de asignaciones y la auditoría son append-only para runtime y operador; el migrador conserva DDL separado. La definición de privilegios compartida por preparación y restauración está en `database/privileges.json`.

El operador necesita UPDATE sobre `iam_controles` para tomar `SELECT ... FOR UPDATE`, además de su acceso de lectura. Se comprobó con un principal limitado y se mantiene el rechazo de DDL y alteraciones del historial/auditoría. Referencia: [locking reads de MySQL 26.7](https://dev.mysql.com/doc/refman/26.7/en/innodb-locking-reads.html).

Organization permite registrar explícitamente empleados asociados a identidades institucionales activas, administrar datos propios de Organization y consultar perfil propio según alcance. No se enlazan identidades por correo ni se permite cambiar el vínculo desde una edición. El correo es de consulta y procede del snapshot verificado IAM. Los asesores son empleados ACTIVO habilitados explícitamente por el administrador; el directorio omite correo e identificadores IAM.

Los puestos MICROSOFT_JOBTITLE se proyectan del perfil verificado de Graph mediante outbox `iam.profile-synchronized.v1`. El evento sólo transporta la referencia y versión; el consumer consulta el snapshot actual mediante un port IAM, conserva los puestos manuales y tolera replay/eventos antiguos. El separador de puestos es espacio + `/` + espacio; cadena vacía equivale a cero puestos. Una falta de `jobTitle` en la respuesta no borra el perfil; un `jobTitle: null` verificado sí finaliza sus puestos Microsoft anteriores. No se infiere ninguna unidad del texto: estos puestos admiten unidad NULL hasta una conciliación explícita, mientras los puestos manuales requieren unidad activa. Se conserva el historial al finalizar puestos.

Las unidades forman un árbol. Un mutex compartido protege cambios de padres y desactivaciones frente a escrituras simultáneas; se rechazan ciclos, referencias a padres inactivos y desactivación con hijos activos o puestos vigentes. Empleados, unidades y niveles tienen versiones para rechazar ediciones sobre datos anteriores. Los grados sólo se gestionan en empleados ACTIVO/EN_LICENCIA con el alcance correspondiente.

Las pantallas Angular `/portal/iam` y `/portal/organization` incluyen roles/permisos, asignaciones/historial, empleados, puestos manuales, grados, árbol y niveles, además del directorio de asesores. Signals mantienen el estado y RxJS realiza I/O mediante el cliente generado. Los guards revalidan `/me`; la autorización definitiva está en el API. Los writes inicializan CSRF y envían la versión esperada, sin reintento automático al recibir 409. No se almacenan tokens Microsoft en el navegador.

## Esquema y adopción

Cada módulo carga sus migraciones. El esquema fresco crea IDs ULID ASCII binarios. La adopción del SQL baseline conserva las tablas y datos: convierte niveles académicos numéricos a ULID, reescribe las FKs de grados y registra `migracion_mapa_ids`; exige conciliación previa si aparecen referencias desconocidas, roles/asignaciones preexistentes o permisos ajenos a las semillas identificadas del baseline. Las semillas de permisos futuros no reciben grants.

MySQL DDL no constituye una transacción reversible completa. Antes de migrar un ambiente persistente debe existir una copia cifrada verificada; se usa roll-forward o restauración del conjunto anterior, nunca `migrate:rollback` como promesa de recuperación. Las dos migraciones rechazan `down()`. `migrate:fresh` se limita a `servicios_moderno_test`. Los catálogos numéricos de otros módulos continúan sujetos al gate de identificadores antes de habilitar cada módulo.

## Secuencia y criterios de salida

1. Validar suite PHP, casos allow/deny y pruebas MySQL aisladas: adopción con grado existente, FKs/ledger, versiones, locks con dos conexiones y rechazo de cambios a auditoría/historial.
2. Ejecutar Pint y Larastan; lint OpenAPI, comparación de contrato, generación reproducible, tests y build Angular.
3. Construir imágenes, migrar el ambiente local persistente con el migrador, aplicar grants y recrear backend/worker/proxy conservando volúmenes y credenciales.
4. Identificar de forma inequívoca la cuenta habitual con la evidencia de login inicial previa al ensayo; ejecutar `iam:bootstrap-administration <ULID>` desde el perfil operador. Exigir un login nuevo después de la asignación.
5. Ejecutar Playwright/TLS. Las respuestas positivas sintéticas prueban la UI y no sustituyen Entra real ni la conciliación del empleado piloto.
6. El responsable comprueba su administración real, registra/confirma su empleado piloto y compara los puestos visibles con su `jobTitle` institucional, conservando la separación de puestos manuales. No compartir datos personales, secretos ni tokens en el reporte.
7. Exigir backend, frontend-openapi, mysql, docker y security-contracts en CI sobre el mismo candidato. Conservar FAIL si los hubiera y corregir su causa.
8. Respaldar las cinco imágenes exactas, SQL/storage/AV/TLS/perfiles cifrados; restaurar en volúmenes nuevos y conservar la clave privada por separado. Custodiar el nuevo conjunto fuera del disco local.

Resultados y estado real de estos pasos se registran en `artifacts/SPRINT_2_VALIDACION_20261010.json` y `artifacts/SPRINT_2_RESULTADOS_20261010.md`, excluidos de Git. No transferir el GO del Sprint 1 a un SHA diferente. El criterio funcional de salida incluye la matriz aceptada, pruebas allow/deny y la conciliación IAM/empleado piloto; mientras falte una evidencia requerida, el cierre se informa pendiente.

El responsable confirmó el 2026-10-10 resultados correctos del ensayo real solicitado: login nuevo con su cuenta habitual, consulta de roles/asignaciones en Administración IAM, registro de su empleado piloto y coincidencia de puestos Microsoft con el perfil institucional. La confirmación no incluye datos personales ni tokens. La cuenta de ensayo no fue elevada durante el bootstrap.
