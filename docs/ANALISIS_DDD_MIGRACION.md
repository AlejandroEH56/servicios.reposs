# Análisis DDD y estrategia de migración a Laravel + Angular

Fecha del análisis: 2026-08-16 · Última actualización: 2026-09-06  
Alcance: `app/Controllers`, `app/Models`, `app/Services`, `app/Filters`, `app/Clases`, rutas, configuración, vistas y pruebas del repositorio.  
Método: reverse engineering estático. Las conclusiones de negocio son inferencias basadas en nombres, consultas, reglas de validación, transacciones, rutas y colaboración entre clases. Deben validarse mediante event storming con usuarios de DRPSS, laboratoristas y administración.

> **Actualización de base de datos (2026-09-05):** se recuperó y analizó el diagrama relacional de `reposs` y `compartida`. El diagnóstico físico, las discrepancias con el código, el modelo objetivo y el plan de reconstrucción/migración se encuentran en [Análisis de bases de datos y modernización](./ANALISIS_BASE_DATOS_MODERNIZACION.md).

## Resumen ejecutivo

La aplicación no es solamente un CRUD institucional. Contiene dos dominios operativos centrales:

1. **Gestión de Residencias Profesionales**: conduce al estudiante desde su registro y preparación documental hasta proyecto, seguimiento, validaciones y liberación.
2. **Gestión del Uso de Laboratorios**: planifica capacidad académica, recibe solicitudes de distintos tipos, evita conflictos temporales y administra su autorización.

Inventarios es un supporting domain de alta complejidad accidental: controla existencias heterogéneas, ubicaciones, movimientos, incidencias, alertas y cierres mensuales. Identidad, organización, archivos, correo, PDF y Excel son capacidades genéricas o compartidas.

El código está organizado por MVC y bases de datos, no por unidades de consistencia. La mayor parte del comportamiento vive en controladores y modelos Active Record. Las entidades de CodeIgniter son en realidad gateways de tabla; solamente `Clases/Solicitudes` se acerca a un modelo de dominio. La migración no debe trasladar controladores y modelos uno a uno. Debe extraer casos de uso y agregados detrás de una API, manteniendo inicialmente las bases existentes mediante adapters.

La estrategia recomendada es un **modular monolith Laravel API-first**, no microservicios iniciales. Angular consume contratos versionados. Se aplica strangler pattern por rutas y capacidades, comenzando con catálogos/read models, luego IAM/ACL, avisos y documentos, y dejando para fases posteriores solicitudes, residencias y el núcleo transaccional de inventarios.

## Modelo de negocio reconstruido

### Flujo de Residencias

Un usuario con correo estudiantil se registra como **Residente** y completa datos académicos. Registra una **Empresa**, un **Asesor Externo** y un **Proyecto**; el proyecto queda asociado a asesores interno y externo, modalidad y programa educativo. El residente carga prerrequisitos, requisitos y reportes. DRPSS valida cada evidencia, genera formatos y cartas, controla estados de reporte y finalmente registra la **Liberación**. Las validaciones y documentos son evidencia del avance; no son simples adjuntos.

### Flujo de Laboratorios

Un empleado consulta la disponibilidad de un **Laboratorio** dentro de un **Semestre**, respetando horarios, días inhábiles y solicitudes existentes. Crea una **Solicitud de Uso** de tipo práctica, varia o extraordinaria. Cada subtipo aporta datos y reglas diferentes. El laboratorista revisa, modifica o autoriza la solicitud. La solicitud autorizada se convierte en ocupación visible del calendario y puede emitirse como PDF o reporte.

### Flujo de Inventarios

Un laboratorista selecciona un laboratorio y, si es necesario, inicializa su inventario. Un **Ítem de Inventario** se especializa en equipo/instrumento, material, reactivo, software o infraestructura. Se localiza en una jerarquía de ubicaciones. Altas, cambios y bajas producen movimientos de bitácora. Las condiciones del ítem producen alertas; las incidencias documentan problemas; un corte mensual congela un resumen y detalles, usando parámetros ABC por laboratorista/laboratorio.

## Clasificación estratégica de dominios

| Clasificación | Dominio | Justificación |
|---|---|---|
| Core | Residencias Profesionales | Representa el proceso institucional diferenciador: elegibilidad, proyecto, evidencias, evaluación y liberación del residente. Su lenguaje y reglas son específicos de la institución. |
| Core | Solicitudes y Uso de Laboratorios | Orquesta disponibilidad, tipos de solicitud, restricciones temporales y autorización; afecta directamente la operación académica. |
| Supporting | Inventarios de Laboratorio | Es esencial para operar laboratorios, pero no define por sí solo la misión académica. Contiene reglas propias de clasificación, alertas, movimientos y cortes. |
| Supporting | Catálogo y Planificación Académica | Carreras, asignaturas, retículas, grupos, semestres, laboratorios y horarios alimentan solicitudes y reportes. |
| Supporting | Estructura Organizacional | Traduce usuarios externos a empleados, puestos, organigrama y grados; provee responsables y asesores a otros contextos. |
| Supporting | Publicación de Avisos y Vacantes | Difunde oportunidades de residencia, servicio social y empleo. |
| Generic | IAM y autorización | OAuth2/Entra ID, sesión, roles y permisos son capacidades estándar. |
| Generic | Gestión documental y comunicaciones | Almacenamiento, PDF, Excel, correo y plantillas son infraestructura reutilizable. |

## Bounded contexts propuestos

```text
Plataforma Institucional
├── IAM
│   ├── Identidad
│   ├── Cuenta institucional
│   ├── Rol / Permiso
│   └── Sesión / Token
├── Organización
│   ├── Empleado
│   ├── Puesto
│   ├── Unidad de organigrama
│   └── Grado académico
├── Residencias
│   ├── Residente
│   ├── Expediente
│   ├── Empresa / Asesor externo
│   ├── Proyecto / Asesor interno
│   ├── Reporte / Validación
│   └── Liberación
├── Planificación Académica de Laboratorios
│   ├── Carrera / Especialidad
│   ├── Asignatura / Retícula / Grupo
│   ├── Semestre
│   └── Laboratorio / Horario / Día inhábil
├── Solicitudes de Laboratorio
│   ├── Solicitud de uso
│   ├── Práctica / Varia / Extraordinaria
│   ├── Autorización
│   └── Calendario de ocupación
├── Inventarios
│   ├── Inventario de laboratorio
│   ├── Ítem tipado
│   ├── Ubicación
│   ├── Movimiento / Incidencia / Alerta
│   └── Corte mensual / Configuración ABC
├── Publicaciones
│   └── Aviso / Vacante
└── Capacidades compartidas
    ├── Archivos y documentos
    ├── PDF / Excel
    ├── Correo / notificación
    └── Auditoría técnica
```

### IAM

- **Objetivo:** autenticar identidades institucionales y decidir capacidades.
- **Responsabilidades:** login/callback/logout OAuth2, vinculación de identidad externa, roles, permisos, sesión y administración RBAC.
- **Entidades:** Identidad, UsuarioGlobal, Rol, Permiso, AsignaciónRol, AsignaciónPermiso, Token.
- **Casos de uso:** iniciar/cerrar sesión, provisionar identidad, asignar/revocar rol, administrar permisos, verificar autorización.
- **Dependencias externas:** Microsoft Entra ID, OAuth2 Client y PHP-RBAC actual.
- **Límite recomendado:** IAM entrega `IdentityId`, claims y permisos; no crea directamente residentes ni puestos. Ese aprovisionamiento debe reaccionar a `IdentidadInstitucionalVinculada`.

### Organización

- **Objetivo:** representar a empleados, cargos y jerarquía institucional.
- **Responsabilidades:** perfil de empleado, vigencia de puesto, organigrama, género del cargo y grados académicos.
- **Entidades:** Empleado, PuestoEmpleado, UnidadOrganizacional, GradoAcadémico, Nivel.
- **Casos de uso:** registrar empleado, asignar/finalizar puesto, actualizar denominación de cargo, mantener grados, buscar docente/asesor/jefe.
- **Dependencias:** IAM como upstream; Residencias y Laboratorios consumen sus identificadores y proyecciones.

### Residencias

- **Objetivo:** completar y comprobar el ciclo institucional de residencia profesional.
- **Responsabilidades:** perfil del residente, empresa, asesores, proyecto, expediente documental, reportes, validaciones, formatos y liberación.
- **Entidades:** Residente, ProgramaEducativo, Empresa, Proyecto, AsesorInterno, AsesorExterno, Documento, Requisito, ReporteParcial, ReporteFinal, Validación, Liberación, Modalidad, Sector y Ramo.
- **Casos de uso:** activar residente, actualizar perfil, registrar empresa/proyecto/asesores, cargar evidencia, validar/rechazar evidencia, emitir carta/constancia, registrar reportes y liberar residente.
- **Dependencias:** IAM, Organización, almacenamiento, PDF, correo y Excel.

### Planificación Académica de Laboratorios

- **Objetivo:** ofrecer el catálogo y la capacidad planificada sobre la que se solicitan laboratorios.
- **Entidades:** Carrera, Especialidad, Asignatura, Retícula, Grupo, Semestre, Laboratorio, Horario, DíaInhábil y TipoDíaInhábil.
- **Casos de uso:** mantener catálogos, activar semestre, configurar laboratorio, asociar currículo y publicar disponibilidad.
- **Dependencias:** Organización para responsables; Solicitudes es downstream.

### Solicitudes de Laboratorio

- **Objetivo:** reservar y autorizar el uso de un laboratorio sin violar disponibilidad ni políticas.
- **Entidades:** Solicitud, DetallePráctica, DetalleVaria, DetalleExtraordinaria, Autorización, TipoUso y Clase.
- **Casos de uso:** consultar calendario, solicitar uso, validar fechas, detectar conflictos, autorizar/rechazar/modificar, descargar comprobante y reportar utilización.
- **Dependencias:** Planificación Académica, Organización, PDF, correo y conversión horaria.

### Inventarios

- **Objetivo:** mantener trazabilidad y estado operativo de bienes e insumos por laboratorio.
- **Entidades:** Inventario, Ítem, EquipoInstrumento, Material, Reactivo, SoftwareLicencia, InfraestructuraSistema, Ubicación, Movimiento, Incidencia, CorteMensual, DetalleCorte, ConfiguraciónABC y DocumentoLaboratorio.
- **Casos de uso:** seleccionar/inicializar inventario, registrar/editar/eliminar ítems, administrar ubicación, registrar incidencia, consultar bitácora/alertas, generar corte y exportar.
- **Dependencias:** catálogo de Laboratorios y Organización/IAM. No debe consultar tablas externas directamente: necesita ACL y snapshots locales.

### Publicaciones

- **Objetivo:** publicar avisos y oportunidades a audiencias internas y públicas.
- **Entidad raíz:** AvisoVacante.
- **Casos de uso:** crear, editar, publicar, retirar, buscar y consultar detalle.
- **Dependencias:** almacenamiento de imágenes y una identidad publicadora.

## Inventario completo de controladores

`DB` indica las bases alcanzadas directa o transitivamente. Las tablas se infieren de los modelos utilizados.

| Controlador | Contexto / subdominio | Casos de uso y responsabilidad | Entidades/tablas principales | Servicios/externos | DB | Riesgo |
|---|---|---|---|---|---|---|
| `Home` | Navegación | Login y selección de dashboard por permiso/puesto | puesto_empleado | RBAC/sesión | compartida | Medio |
| `ErrorWorker` | Shared UI | Render de error | — | HTTP | — | Bajo |
| `Inv/Inventarios` | Inventarios / catálogo y ubicación | Inicializar/borrar inventario, CRUD polimórfico de ítems, jerarquía de ubicación, reglamento PDF | inventario, cinco tablas de subtipo, cat_ubicacion, inventario_inicializacion, bitacora_movimientos | Alertas, documentos, filesystem, SQL | inventarios + laboratorios | Muy alto |
| `Inv/Bitacora` | Inventarios / auditoría | Consultar/eliminar/exportar movimientos | bitacora_movimientos, inventario y subtipos | Alertas, Excel | inventarios | Alto |
| `Inv/Cortes` | Inventarios / cierre | Configurar ABC, generar/borrar/exportar cierres | cortes_mensuales, cortes_mensuales_detalle, abc_config, inventario | Alertas, Excel, transacciones | inventarios | Muy alto |
| `Inv/Incidencias` | Inventarios / incidencias | CRUD y exportación de incidencias | incidencias, inventario | Alertas, Excel | inventarios | Alto |
| `Inv/PermisosLaboratorio` | IAM de Inventarios | Buscar laboratorista, asignar/revocar/activar permiso y edición | inventarios_admin, usuario/laboratorio | sesión, SQL | inventarios + compartida + laboratorios | Alto |
| `Labs/.../Asignatura` | Planificación / catálogo | CRUD de asignaturas y auditoría | asignatura, bitacora | Bitácora | laboratorios + compartida | Medio |
| `Labs/.../Carrera` | Planificación / catálogo | CRUD de carreras | carrera, bitacora | Bitácora | laboratorios + compartida | Medio |
| `Labs/.../DiasInhabiles` | Planificación / calendario | CRUD de periodos no disponibles | dias_inhabiles, tipo_dia_inhabil | Bitácora | laboratorios | Medio |
| `Labs/.../Especialidad` | Planificación / currículo | CRUD de especialidades por carrera | especialidad, carrera | Bitácora | laboratorios | Medio |
| `Labs/.../Grupo` | Planificación / oferta | CRUD de grupos por carrera/semestre | grupo, carrera, semestre | Bitácora | laboratorios | Medio |
| `Labs/.../Horario` | Planificación / capacidad | CRUD de horario por laboratorio/semestre | horario, laboratorio, semestre | Bitácora | laboratorios | Alto |
| `Labs/.../Laboratorios` | Planificación / recursos | CRUD; impide borrado con solicitudes | laboratorio, carrera, solicitud | Bitácora | laboratorios | Alto |
| `Labs/.../Reportes` | Solicitudes / reporting | Reporte mensual/anual de uso | solicitud, semestre | PhpSpreadsheet | laboratorios | Medio |
| `Labs/.../Reticula` | Planificación / currículo | Asociar carrera, especialidad y asignatura | reticula, carrera, especialidad, asignatura | Bitácora | laboratorios | Medio |
| `Labs/.../Semestre` | Planificación / periodo | CRUD y activación de semestre | semestre | Bitácora | laboratorios | Alto |
| `Labs/.../SolicitudLaboratorio` | Solicitudes / operador | Calendario, búsqueda, creación, edición/autorización y PDF | solicitud y detalles práctica/varia/extraordinaria; laboratorio, horario, días inhábiles | ConversionHoras, PDF, objetos Solicitud | laboratorios + compartida | Muy alto |
| `Labs/MenuUsuario/SolicitarLaboratorio` | Solicitudes / solicitante | Consultar disponibilidad, crear y consultar solicitud, PDF | mismas tablas de solicitud y planificación | ConversionHoras, PDF, RBAC | laboratorios + compartida | Muy alto |
| `OAuthlogin/OAuthGlobals` | IAM / provisioning | OAuth, crear/vincular usuario global, residente/empleado, puesto y rol | usuarios_globales, usuario, residente, puesto_empleado, organigrama, userroles | Microsoft OAuth2, RBAC | compartida + reposs | Muy alto |
| `OAuthlogin/OAuthController` | IAM legacy | Implementación anterior del mismo flujo | mismas tablas | Microsoft OAuth2, RBAC | compartida + reposs | Alto (retirar) |
| `OAuthlogin/MSMailSend` | Infraestructura | Graph/mail con adjuntos | token de sesión | Microsoft Graph/Guzzle | — | Alto |
| `OAuthlogin/OAuthErrors` | IAM UI | Mostrar error de autenticación | — | sesión | — | Bajo |
| `Puestos/Puesto` | Organización / perfil | Perfil, género de cargo y CRUD de grados | usuario, puesto_empleado, grado_academico, nivel | sesión | compartida | Alto |
| `Reposs/AdminController` | IAM / RBAC admin | CRUD de roles/permisos y asignaciones | phpRbca_roles, permissions, rolepermissions, userroles, usuarios_globales | PHP-RBAC | compartida | Alto |
| `Reposs/OpenUseController` | IAM diagnóstico | Vista de roles y permisos | tablas RBAC | — | compartida | Bajo (retirar/restringir) |
| `Reposs/RutaDocumentos` | Infraestructura | Resolver y guardar archivo | filesystem | UploadedFile | — | Alto |
| `Formatos/ConstanciaAsesorInternoPdf` | Residencias / emisión | Generar constancia con folio y logos | constancia_asesoria, imagenes_recursos | Dompdf | reposs + compartida | Alto |
| `Formatos/Formatos` | Documentos institucionales | Carta, CRUD/subida de formatos | formatos_institucionales, documento, empresa | filesystem | reposs + compartida | Alto |
| `Formatos/Imagenes` | Documentos institucionales | CRUD/activación de logos | imagenes_recursos, formatos_institucionales | ResourceController/filesystem | compartida | Medio |
| `Formatos/PdfController` | Residencias / emisión | Solicitud RP, cartas formal/informal y reportes | residente, proyecto, empresa, puesto, logos | Dompdf | reposs + compartida | Muy alto |
| `MenusDRPSS/AsesorInterno` | Residencias / asesoría | Listar/consultar asesores y proyectos | asesor_interno, programa_educativo, proyecto | — | reposs | Medio |
| `MenusDRPSS/AvisosVacantes` | Publicaciones | CRUD de avisos con imagen | aviso_vacante | filesystem | reposs | Alto |
| `MenusDRPSS/ConstanciaAsesorInterno` | Residencias UI | Formulario de constancia | — | — | — | Bajo |
| `MenusDRPSS/DocumentosDisponibles` | Residencias UI | Catálogo visual de documentos | — | — | — | Bajo |
| `MenusDRPSS/DocumentosDRPSS` | Residencias / revisión | Listar/ver/descargar y validar documentos/reportes | documento, validacion, residente, programa, puesto | Notificación, filesystem | reposs + compartida | Muy alto |
| `MenusDRPSS/HomeDRPSS` | Residencias UI | Dashboard DRPSS | — | — | — | Bajo |
| `MenusDRPSS/Liberacion` | Residencias / cierre | Consultar estado, cargar y actualizar liberación | liberacion, residente, proyecto, requisito, documento, reporte_final | filesystem | reposs + compartida | Muy alto |
| `MenusDRPSS/ReportesExcel` | Residencias / reporting | Filtros y reporte de residentes | residente y joins de proyecto/empresa/catálogos | PhpSpreadsheet | reposs | Alto |
| `MenusDRPSS/Residente` | Residencias / administración | Buscar, listar, activar y consultar perfil | residente, programa, usuarios_globales, userroles | RBAC | reposs + compartida | Alto |
| `MenusResidente/AsesorExterno` | Residencias / empresa | CRUD/búsqueda de asesor externo | asesor_externo, empresa, proyecto, sector, ramo, residente | — | reposs | Alto |
| `MenusResidente/DatosResidente` | Residencias / perfil | Actualizar residente y validar CURP | residente, programa_educativo | reglas CURP | reposs | Alto |
| `MenusResidente/Documentos` | Residencias / expediente | Cargar y administrar prerrequisitos/requisitos | documento, pre_requisito, requisito, tipo_archivo, validacion, residente | filesystem, RBAC | reposs | Muy alto |
| `MenusResidente/Empresa` | Residencias / vinculación | CRUD/búsqueda empresa y asociación con proyecto | empresa, asesor_externo, proyecto, sector, ramo, residente | RFCValidador | reposs | Muy alto |
| `MenusResidente/HomeResidente` | Residencias UI | Dashboard residente | — | sesión | — | Bajo |
| `MenusResidente/Proyecto` | Residencias / proyecto | CRUD proyecto, asesores, empresa y formato | proyecto, empresa, asesores, residente, sector, ramo, usuario | queries cruzadas | reposs + compartida | Muy alto |
| `MenusResidente/Reportes` | Residencias / seguimiento | Carga, clasificación y borrado de reportes | reporte_parcial, reporte_final, documento, validacion, proyecto, residente | filesystem, RBAC | reposs | Muy alto |
| `MenusResidente/Vacantes` | Publicaciones UI | Mostrar vacantes | aviso_vacante vía vista pública | — | reposs | Bajo |
| `Publico/AvisosPublicoController` | Publicaciones | Listar, filtrar y ver avisos/bolsa | aviso_vacante | paginación | reposs | Medio |

## Inventario completo de modelos

Los modelos CI son repositorios/table gateways, no entidades ricas. “Root” indica el papel recomendado, no el actual.

| Modelo → tabla (DB) | Entidad candidata | Papel DDD / responsabilidad |
|---|---|---|
| `Inv/InventarioModel` → inventario (inventarios) | ÍtemInventario | Root de ciclo de vida del ítem; hoy contiene solamente persistencia. |
| `EquipoInstrumentoModel` → equipos_instrumentos | DetalleEquipo | Hijo 1:1 de ÍtemInventario. |
| `MaterialModel` → materiales | DetalleMaterial | Hijo 1:1 tipado. |
| `ReactivoModel` → reactivos_sustanciasquimicas | DetalleReactivo | Hijo con caducidad, cantidad y riesgo químico. |
| `SoftwareLicenciaModel` → software_licencias | DetalleSoftware | Hijo con vigencia/licencia. |
| `InfraestructuraSistemaModel` → infraestructura_sistemas | DetalleInfraestructura | Hijo tipado. |
| `CatUbicacionModel` → cat_ubicacion | Ubicación | Root de árbol jerárquico o catálogo independiente. |
| `BitacoraMovimientoModel` → bitacora_movimientos | Movimiento | Evento persistido/auditoría de Ítem. |
| `IncidenciaModel` → incidencias | Incidencia | Root asociado a ítem/laboratorio. |
| `CorteMensualModel` → cortes_mensuales | CorteMensual | Root inmutable después de cierre. |
| `CorteMensualDetalleModel` → cortes_mensuales_detalle | DetalleCorte | Hijo del corte; snapshot. |
| `AbcConfigModel` → abc_config | PolíticaABC | Configuración por laboratorista y laboratorio. |
| `InventarioInicializacionModel` → inventario_inicializacion | InicializaciónInventario | Estado/idempotency record. |
| `InventariosAdminModel` → inventarios_admin | PermisoInventario | ACL local; mover conceptualmente a IAM/policy. |
| `LaboratorioDocumentoModel` → laboratorio_documentos | ReglamentoLaboratorio | Documento asociado al laboratorio. |
| `Labs/SolicitudModel` → solicitud (laboratorios) | SolicitudUso | Aggregate Root potencial; crea/actualiza detalles en transacción. |
| `SolicitudesPracticasModel` → solicitudes_practicas | DetallePráctica | Hijo de SolicitudUso. |
| `SolicitudesVariasModel` → solicitudes_varias | DetalleVaria | Hijo de SolicitudUso. |
| `SolicitudesExtraordinariasModel` → solicitudes_extraordinarias | DetalleExtraordinaria | Hijo de SolicitudUso. |
| `AutorizacionModel` → autorizacion | EstadoAutorización | Value/reference data; idealmente enum + decisión auditada. |
| `TipoUsoModel` → tipo_uso | TipoUso | Catálogo/value object. |
| `ClaseModel` → clase | ClaseAcadémica | Referencia de una práctica. |
| `HorarioModel` → horario | HorarioLaboratorio | Root de capacidad planificada; también hace queries de solicitudes, violando límite. |
| `LaboratorioModel` → laboratorio | Laboratorio | Root del catálogo de espacios. |
| `SemestreModel` → semestre | Semestre | Root de periodo académico; debe garantizar uno activo. |
| `DiasInhabilesModel` → dias_inhabiles | DíaInhábil | Root de bloqueo temporal. |
| `TipoDiaInhabilModel` → tipo_dia_inhabil | TipoDíaInhábil | Catálogo. |
| `CarreraModel` → carrera | Carrera | Root de catálogo académico. |
| `EspecialidadModel` → especialidad | Especialidad | Hija/referencia de Carrera. |
| `AsignaturaModel` → asignatura | Asignatura | Root de catálogo. |
| `ReticulaModel` → reticula | EntradaRetícula | Asociación Carrera–Especialidad–Asignatura. |
| `GrupoModel` → grupo | Grupo | Oferta por carrera y semestre. |
| `BitacoraModel` → bitacora | EntradaAuditoría | Auditoría técnica, no dominio central. |
| `PuestoEmpleado/UserModel` → usuario (compartida) | Empleado | Root de perfil organizacional. |
| `PuestoEmpleadoModel` → puesto_empleado | AsignaciónPuesto | Hijo temporal de Empleado; conecta organigrama. |
| `OrganigramaModel` → organigrama | UnidadOrganizacional | Root jerárquico/reference data. |
| `GradoAcademicoModel` → grado_academico | GradoAcadémico | Hijo de Empleado. |
| `NivelModel` → nivel | NivelAcadémico | Catálogo/value object. |
| `Roles/UsuariosGlobales` → usuarios_globales | IdentidadLocal | Root de vinculación entre Entra y perfiles locales. |
| `UserRolesModel` → phpRbca_userroles | AsignaciónRol | Asociación IAM. |
| `RolesModel` → phpRbca_roles | Rol | Root IAM. |
| `PermissionsModel` → phpRbca_permissions | Permiso | Root/reference IAM. |
| `RolePermissionsModel` → phpRbca_rolepermissions | Concesión | Asociación Rol–Permiso. |
| `Reposs/ResidenteModel` → residente (reposs) | Residente | Root del participante y dueño lógico del expediente. |
| `ProyectoModel` → proyecto | ProyectoResidencia | Root del compromiso empresa–residente–asesores. |
| `EmpresaModel` → empresa | EmpresaColaboradora | Root reutilizable; actualmente su propiedad por usuario es ambigua. |
| `AsesorExternoModel` → asesor_externo | AsesorExterno | Entidad de Empresa/Proyecto. |
| `AsesorInternoModel` → asesor_interno | AsesorInterno | Rol del empleado dentro del Proyecto. |
| `ProgramaEducativoModel` → programa_educativo | ProgramaEducativo | Catálogo académico local de Residencias. |
| `ModalidadModel` → modalidad | Modalidad | Catálogo/value object. |
| `SectorModel` → sector | Sector | Catálogo empresarial. |
| `RamoModel` → ramo | Ramo | Catálogo empresarial. |
| `DocumentoModel` → documento | Documento | Metadata de archivo; participa en Expediente. |
| `TipoArchivoModel` → tipo_archivo | TipoDocumento | Catálogo de evidencia. |
| `PreRequisitoModel` → pre_requisito | EvidenciaPrevia | Hijo de Expediente; asociación residente-documento. |
| `RequisitoModel` → requisito | EvidenciaRequerida | Hijo de Expediente/liberación. |
| `ReporteParcialModel` → reporte_parcial | ReporteParcial | Hijo del Seguimiento de Proyecto. |
| `ReporteFinalModel` → reporte_final | ReporteFinal | Hijo del Seguimiento; requisito de liberación. |
| `ValidacionModel` → validacion | DecisiónValidación | Entidad auditada sobre evidencia/reporte. |
| `LiberacionModel` → liberacion | Liberación | Root del cierre del proceso. |
| `ConstanciaAsesoriaModel` → constancia_asesoria | ConstanciaAsesoría | Root documental con asignación transaccional de folio. |
| `AvisoVacanteModel` → aviso_vacante | Aviso | Root de Publicaciones. |
| `FormatosModel` → formatos_institucionales (compartida) | PlantillaInstitucional | Root de gestión documental. |
| `ImagenesModel` → imagenes_recursos (compartida) | RecursoVisual | Hijo de plantilla/recurso institucional. |
| `ReportesExcelModel` → residente | ReadModelReporteResidencias | Query service, no entidad ni repositorio de agregado. |
| `UserTokenModel` → user_tokens | TokenOAuth | Credencial IAM; debe cifrarse/aislarse. |

## Servicios y clases de dominio

| Clase | Clasificación actual | Ubicación futura | Observación |
|---|---|---|---|
| `ConversionHoras` | Servicio de dominio técnico | Shared Kernel `Clock/TimeZone` | Conversión UTC/local; preferir objetos de tiempo y timezone explícito. |
| `RFCValidador` | Servicio de dominio | Residencias/Empresas | Regla pura de identificación fiscal; convertir a `RFC` value object. |
| `InventarioAlertasService` | Servicio de dominio/query híbrido | Inventarios Domain + Application Query | Detecta alertas por tipo, pero también introspecciona tablas y arma sidebar. Separar política de alerta de consulta. |
| `LaboratorioDocumentosService` | Aplicación + infraestructura | Inventarios Application/Infrastructure | Crea tablas, consulta DB y borra archivos; reemplazar por migración, repositorio y storage port. |
| `SolicitudLaboratorioPdf` | Infraestructura | Solicitudes Infrastructure/Pdf | Adapter de representación. |
| `CorreoElectronico`, `MSMail` | Infraestructura | Shared Infrastructure/Mail | Duplicados; implementar `Mailer` port con adapters SMTP/Graph. |
| `Notificacion` | Aplicación + infraestructura | Residencias Application | Decide cuándo notificar y construye HTML/envía correo; separar caso de uso, plantilla y transport. |
| `Clases/Solicitudes/*` | Entidades/value objects anémicos incipientes | Solicitudes Domain | Preservar conceptos; agregar estados, invariantes y factories, retirar arrays como contrato interno. |
| `RuleSets/MisReglas` | Políticas de dominio expuestas como validator | Solicitudes Domain | Reglas de fecha/hora deben vivir en políticas testeables. |
| `RutaDocumentos` | Infraestructura mal ubicada como controller | Shared Infrastructure/Storage | Convertir a adapter de `DocumentStorage`. |

## Filtros y políticas de acceso

| Filtro | Política inferida | Destino Laravel |
|---|---|---|
| `RoleFilter` (`rbac`) | Requiere sesión/token vigente y permiso route-level | Middleware `auth:sanctum`/OIDC + Policies/Gates; nunca asumir que token es objeto no nulo. |
| `NumeroControlFilter` | Restringe acceso a un residente/número de control | `ResidentPolicy::view/update`, comparando actor y recurso; evitar seguridad basada solo en segmento URL. |
| `Residente` (`FilterRest`) | Autoriza dominios/rutas de residente | Middleware de claims solo para onboarding; autorización de recurso en Policy. |
| `InventariosAdminFilter` | Usuario actual debe ser admin activo del inventario/laboratorio | `InventoryPolicy` con permiso contextual `laboratory_id`; ACL local o claim enriquecido. |
| CSRF global | Protección de formularios server-rendered | En API SPA usar Sanctum cookie+CSRF o bearer/OIDC con CORS estricto. |

## Agregados, invariantes y reglas

### Residente / Expediente

- **Root:** Residente o, preferiblemente, `ExpedienteResidencia` separado del perfil.
- **Hijos:** evidencias de prerrequisito/requisito, referencias de documentos y decisiones de validación.
- **Invariantes:** número de control único; correo pertenece al dominio estudiantil; CURP válida cuando aplique; una evidencia vigente por tipo/etapa; solo DRPSS puede validar; una nueva carga invalida o versiona la validación previa.

### ProyectoResidencia

- **Root:** ProyectoResidencia.
- **Hijos/referencias:** asignaciones de empresa, asesor externo, asesor interno, modalidad y periodo.
- **Invariantes:** pertenece a un residente; la empresa es global y puede aportar varios asesores externos; empresa/asesores deben existir y estar vigentes; fechas ordenadas; un asesor externo corresponde a la empresa; no liberar sin proyecto válido; máximo un proyecto por residente y periodo, permitiendo históricos en periodos distintos; no se admiten intervalos activos solapados.

### SeguimientoResidencia

- **Root:** Seguimiento o ProyectoResidencia.
- **Hijos:** ReporteParcial, ReporteFinal, Documento y Validación.
- **Invariantes:** periodos de 5/6 meses requieren 3 parciales y 1 final, y el de 4 meses requiere 2 parciales y 1 final; reporte final posterior a los parciales requeridos; validación solo sobre una versión cargada; revisiones ilimitadas conservan historial y la última decisión es la vigente; calificación y estado coherentes.

### Liberación

- **Root:** Liberación.
- **Hijos/referencias:** requisitos finales, reporte final y documentos de cierre.
- **Invariantes:** única por residente/proyecto; proyecto y evidencias requeridas aprobados; puede reabrirse, pero cada transición conserva estado anterior/nuevo, motivo, fecha, responsable y versión; el estado materializado coincide con el último historial.

### SolicitudUsoLaboratorio

- **Root:** SolicitudUso.
- **Hijo exclusivo:** uno de DetallePráctica, DetalleVaria o DetalleExtraordinaria.
- **Invariantes:** exactamente un subtipo; inicio < fin; dentro de semestre/horario permitido o justificación extraordinaria; no superposición con bloqueos/solicitudes autorizadas; laboratorio y solicitante válidos; transiciones `BORRADOR→PENDIENTE→AUTORIZADA|RECHAZADA|CANCELADA`; solo laboratorista decide; solicitudes pasadas no se editan salvo política explícita.

### Semestre y HorarioLaboratorio

- **Roots:** Semestre y HorarioLaboratorio.
- **Invariantes:** máximo un semestre activo por alcance; rangos válidos; un horario pertenece a laboratorio y semestre; franjas no solapadas.

### ÍtemInventario

- **Root:** ÍtemInventario.
- **Hijo exclusivo:** detalle tipado; ubicación como referencia.
- **Invariantes:** exactamente un tipo/detalle; pertenece a un laboratorio; cantidades no negativas; unidad compatible; serie/inventario únicos cuando apliquen; caducidad/licencia coherente; cambio material genera Movimiento; borrado requiere política y auditoría.

### Ubicación

- **Root:** ÁrbolUbicaciones por laboratorio o cada Ubicación con parent.
- **Invariantes:** sin ciclos; mismo laboratorio; raíz y niveles válidos; no eliminar nodos con hijos o ítems; nombres únicos entre hermanos según política.

### CorteMensual

- **Root:** CorteMensual.
- **Hijos:** DetallesCorte snapshot.
- **Invariantes:** uno por laboratorio/periodo; detalles corresponden al snapshot de generación; configuración ABC válida; una vez cerrado es inmutable o se versiona; borrar historial es operación administrativa auditada.

### Aviso

- **Root:** Aviso.
- **Invariantes:** tipo permitido; vigencia coherente; solo avisos publicados y vigentes aparecen públicamente; archivo asociado seguro.

## Eventos de dominio implícitos

| Evento | Productor | Consumidores posibles |
|---|---|---|
| `IdentidadInstitucionalVinculada` | IAM | Organización/Residencias provisionan perfil. |
| `RolAsignado`, `RolRevocado` | IAM | Auditoría, navegación/proyecciones. |
| `EmpleadoRegistrado`, `PuestoAsignado`, `PuestoFinalizado` | Organización | IAM claims, Residencias, Laboratorios. |
| `ResidenteRegistrado`, `PerfilResidenteCompletado` | Residencias | Expediente, notificaciones. |
| `EmpresaRegistrada`, `AsesorExternoAsignado` | Residencias | Proyecto. |
| `ProyectoResidenciaCreado`, `ProyectoActualizado` | Residencias | Formatos, seguimiento, DRPSS. |
| `DocumentoCargado`, `DocumentoReemplazado` | Expediente | Revisión/notificación. |
| `DocumentoAprobado`, `DocumentoRechazado` | Expediente | Residente, liberación, correo. |
| `ReporteParcialEntregado`, `ReporteFinalEntregado` | Seguimiento | Validación/liberación. |
| `ResidenteLiberado` | Liberación | Constancias, reporting, IAM lifecycle. |
| `SemestreActivado` | Planificación | Solicitudes/calendario. |
| `HorarioLaboratorioPublicado` | Planificación | Solicitudes. |
| `SolicitudLaboratorioCreada` | Solicitudes | Calendario, laboratorista, correo. |
| `SolicitudAutorizada`, `SolicitudRechazada`, `SolicitudCancelada` | Solicitudes | Calendario, solicitante, reporting. |
| `InventarioInicializado` | Inventarios | UI/auditoría. |
| `ItemInventarioRegistrado`, `ItemReubicado`, `ExistenciaAjustada`, `ItemRetirado` | Inventarios | Bitácora, alertas. |
| `IncidenciaReportada`, `IncidenciaResuelta` | Inventarios | Alertas/notificación. |
| `AlertaInventarioDetectada` | Inventarios | Sidebar/correo. |
| `CorteMensualGenerado` | Inventarios | Reportes/auditoría. |
| `AvisoPublicado`, `AvisoRetirado` | Publicaciones | Portal público. |

Inicialmente estos eventos pueden ser clases internas + outbox en el monolito. No requieren un broker desde el primer día.

## Context map

### Dependencias actuales

```text
Microsoft Entra
    ↓ Conformist (payload Graph)
OAuthGlobals ──escribe──> compartida.usuario/usuarios_globales/RBAC/puesto
      └────────escribe──> reposs.residente

Residencias ──consulta directa──> compartida.usuario/puesto/organigrama
Laboratorios ─consulta directa──> compartida.usuario/puesto
Inventarios ─consulta SQL directa──> laboratorios.laboratorio + compartida.usuario

Todos ──acoplamiento──> sesión CI + filesystem + vistas + Dompdf/Excel/correo
```

Problemas: shared database, IDs con semántica global implícita, joins/queries cruzadas, provisioning síncrono dentro de OAuth y ausencia de contratos versionados.

### Dependencias deseadas

```text
Entra ID --[ACL OIDC]--> IAM
IAM --[Published Language: IdentityRef/claims]--> Organización, Residencias, Laboratorios, Inventarios
Organización --[Customer/Supplier: EmployeeRef]--> Residencias y Laboratorios
Planificación --[Customer/Supplier: LaboratorySchedule API/events]--> Solicitudes
Planificación --[Published Language: LaboratoryRef]--> Inventarios
Residencias --[Partnership interno]--> Documentos/Notificaciones
Solicitudes --[ports]--> PDF/Notificaciones
Inventarios --[ports]--> Storage/Excel/Notificaciones
```

- **Shared Kernel mínimo:** IDs tipados, Clock, Money/quantities si aparecen, Result/Error contract, actor/auditoría. No compartir modelos Eloquent.
- **Customer/Supplier:** Planificación es supplier de Solicitudes; Organización es supplier de perfiles de empleado.
- **Partnership:** Expediente, Seguimiento y Liberación pueden iniciar como módulos internos del contexto Residencias.
- **Conformist actual:** payload/nombres de Entra y esquema PHP-RBAC. Sustituir por ACL.
- **ACL necesarias:** `EntraIdentityProvider`, `LegacyRbacAdapter`, `LegacyEmployeeDirectory`, `LegacyLaboratoryCatalog`, `LegacyDocumentStorage` y repositorios sobre esquemas actuales.

## Dependencias cruzadas y deuda técnica

### God Controllers

| Archivo | Líneas | Diagnóstico |
|---|---:|---|
| `Inv/Inventarios.php` | 5,119 | Une ubicación, autorización contextual, archivos, inicialización, cinco tipos de ítem, SQL, transacciones, vistas y auditoría. Prioridad crítica de descomposición. |
| `Inv/Bitacora.php` | 1,104 | Query/report controller con lógica de joins, traducción de tipos, exportación y descarga. |
| `Inv/Cortes.php` | 1,074 | Calcula ABC, crea snapshots, transacciones y Excel. Esconde reglas financieras/operativas. |
| `Labs/.../SolicitudLaboratorio.php` | 908 | Orquesta catálogo, calendario, reglas, tres subtipos, autorización y PDF. |
| `Inv/Incidencias.php` | 833 | CRUD, resolución de referencias, alertas y Excel. |
| `Labs/.../SolicitarLaboratorio.php` | 696 | Duplicación del flujo anterior desde otro actor. |
| `OAuthGlobals.php` | 581 | Autenticación más provisioning de tres contextos y RBAC. |

### Anemia y acoplamiento

- Los modelos extienden `CodeIgniter\Model`, exponen arrays y concentran queries; no protegen invariantes.
- `SolicitudModel` es simultáneamente repositorio, transaction script, report query y factory de tres subtipos.
- `HorarioModel` consulta solicitudes, mezclando planificación con workflow.
- Residencias usa modelos de `PuestoEmpleado` directamente para asesores/jefaturas.
- OAuth crea residentes, empleados, puestos y roles en el callback: una falla parcial atraviesa bases distintas sin transacción distribuida.
- Inventarios consulta catálogos de otras bases y contiene SQL específico de esquema en controladores.
- Filesystem paths, HTTP, sesión, validación, SQL y presentación aparecen en la misma clase.
- `CorreoElectronico`, `MSMail` y `MSMailSend` representan capacidades solapadas.
- `OAuthController` y `OAuthGlobals` son implementaciones duplicadas/derivadas; rutas usan la segunda y pruebas se enfocan en la primera.
- CRUD de catálogos Labs repite constructor, validación, flash, bitácora y render.
- Flujos de solicitante/laboratorista duplican carga de catálogos, calendario, construcción de solicitudes y PDF.
- Vistas y endpoints usan nombres de ruta orientados a pantallas (`mostrar`, `nuevo`, `editar`) en lugar de recursos/casos de uso.

### Violaciones SOLID

- **SRP:** controladores de Inventarios, Solicitudes, OAuth y PDF tienen múltiples razones de cambio.
- **OCP:** tipos de inventario y tipos de solicitud se resuelven con ramas; agregar un tipo obliga a modificar controladores y queries.
- **LSP:** controladores mezclan `Controller`, `BaseController` y `ResourceController`; las convenciones/contratos varían.
- **ISP:** no hay interfaces de repositorio/ports; consumidores dependen de modelos completos.
- **DIP:** casos de uso instancian modelos/servicios concretos y dependen de CI, Dompdf, Graph y filesystem.

### Seguridad y operabilidad

- Credenciales MySQL están versionadas en `Database.php`; deben rotarse y migrarse a secretos de entorno.
- `OAuth.php` está ignorado correctamente, pero falta plantilla segura y documentación de variables.
- El token OAuth se conserva en sesión y código asume que siempre es un objeto válido.
- Hay acciones destructivas históricas y GET de eliminación en Labs; todas deben ser comandos POST/DELETE con policy, CSRF/idempotencia y auditoría.
- Solamente dos migraciones representan decenas de tablas; el esquema no es reproducible.
- `LaboratorioDocumentosService` crea tabla en runtime, saltándose control de versión.
- Existen solo siete pruebas, varias de plantilla y las OAuth apuntan a código legado.
- `vendor` y PHP no estaban disponibles durante el análisis; no fue posible ejecutar pruebas/lint.

## Riesgo por módulo

| Módulo | Riesgo | Causa dominante | Tratamiento |
|---|---|---|---|
| IAM/OAuth | Muy alto | Seguridad, tokens, provisioning multibase y RBAC legacy | OIDC ACL, shadow login, pruebas contractuales y rollback compensatorio. |
| Residencias | Muy alto | Workflow documental implícito, archivos, validaciones y joins compartidos | Event storming, caracterización, migrar por slices de expediente. |
| Solicitudes Labs | Muy alto | Reglas temporales, concurrencia y tres subtipos | Golden-master de disponibilidad; lock/unique constraints. |
| Inventarios | Muy alto | God controllers, tipos heterogéneos, SQL y cierres | Extraer read model; luego comandos por tipo y corte versionado. |
| Planificación Labs | Medio/alto | CRUD sencillo pero alimenta disponibilidad | Migrable temprano con API compatible. |
| Organización | Alto | Identificadores compartidos y jerarquía | Published language + snapshots/referencias. |
| Publicaciones | Bajo/medio | CRUD y archivos | Primer vertical slice candidato. |
| PDF/Excel/correo | Medio/alto | Plantillas visuales y proveedores externos | Ports/adapters y comparación byte/visual/semántica. |

## Arquitectura objetivo Laravel

Se recomienda un modular monolith con dependencias unidireccionales y Composer namespaces por módulo:

```text
app/Modules/
├── IAM/
├── Organization/
├── Residencies/
├── AcademicPlanning/
├── LaboratoryRequests/
├── Inventories/
├── Publications/
└── Shared/
    ├── Domain
    ├── Application
    └── Infrastructure
```

Cada módulo:

```text
Module/
├── Domain/
│   ├── Aggregates, Entities, ValueObjects
│   ├── Events, Policies, Exceptions
│   └── Contracts (Repository interfaces)
├── Application/
│   ├── Commands, Queries, DTOs
│   ├── Handlers
│   └── Ports
├── Infrastructure/
│   ├── Persistence/Eloquent
│   ├── ExternalAdapters
│   └── Providers
└── Presentation/Http/
    ├── Controllers
    ├── Requests
    ├── Resources
    └── Policies
```

### Contratos por módulo

| Módulo | Entidades/agregados | Casos de uso iniciales | Repositories | Policies | API Resources |
|---|---|---|---|---|---|
| IAM | Identity, Role, Permission | Login callback, link identity, grant/revoke | IdentityRepository, AuthorizationRepository | RolePolicy, PermissionPolicy | CurrentUserResource, RoleResource |
| Organization | Employee, PositionAssignment, OrgUnit | Maintain profile/degree, assign/finalize position, search adviser | EmployeeRepository, OrgUnitRepository | EmployeePolicy | EmployeeSummaryResource, PositionResource |
| Residencies | ResidentFile, ResidencyProject, FollowUp, Release | Complete profile, register company/project, upload/review evidence, release | ResidentFileRepository, ProjectRepository, ReleaseRepository | ResidentFilePolicy, ReviewPolicy | ResidentResource, ProjectResource, EvidenceResource, ReleaseResource |
| AcademicPlanning | Semester, Laboratory, Schedule, Curriculum | Activate semester, publish schedule, maintain catalogs | SemesterRepository, LaboratoryRepository, ScheduleRepository | PlanningPolicy | LaboratoryResource, AvailabilityResource |
| LaboratoryRequests | LaboratoryRequest | Quote availability, submit, approve/reject/cancel | RequestRepository, AvailabilityGateway | RequestPolicy | LaboratoryRequestResource, CalendarEventResource |
| Inventories | InventoryItem, LocationTree, Incident, MonthlyClose | Register/move/adjust/retire, report incident, close month | ItemRepository, LocationRepository, CloseRepository | InventoryPolicy | InventoryItemResource, AlertResource, CloseResource |
| Publications | Notice | Draft/publish/withdraw/search | NoticeRepository | NoticePolicy | NoticeResource |

Convenciones API:

- `/api/v1/...`, JSON:API-like consistente sin acoplarse obligatoriamente al estándar.
- Controllers delgados; Form Requests validan forma, Domain protege invariantes.
- Commands transaccionales y Queries optimizadas/CQRS ligero.
- UUID/ULID nuevos o tabla de correspondencia `legacy_id_map`; no cambiar PK legacy durante coexistencia.
- Outbox para eventos posteriores al commit.
- Laravel Storage para archivos; URLs firmadas y antivirus/MIME real.
- Policies por recurso y permiso contextual, no únicamente middleware por rol.
- OpenAPI como contrato fuente; tests de contrato para Angular y adapters legacy.

## Arquitectura Angular objetivo

Aplicación standalone o feature-first con lazy loading:

```text
src/app/
├── core/          auth, http interceptors, error handling, layout
├── shared/        UI, pipes, forms, typed utilities
└── features/
    ├── iam/
    ├── organization/
    ├── residencies/
    ├── academic-planning/
    ├── laboratory-requests/
    ├── inventories/
    └── publications/
```

| Feature | Componentes/páginas | Servicios/facades | Guards y rutas |
|---|---|---|---|
| IAM | callback, access-denied, role-admin | AuthFacade, PermissionService | `authGuard`, `permissionGuard` |
| Organization | employee-profile, degrees, position editor/search | EmployeeApi, OrganizationFacade | `/organization/employees/:id` |
| Residencies | resident-dashboard, profile, company, project, evidence-list/upload/review, reports, release | ResidencyFacade, DocumentUploadService | resident/self and DRPSS reviewer guards |
| AcademicPlanning | catalogs, semester, laboratory, schedule editor | PlanningApi, CalendarFacade | laboratorista/admin guard |
| LaboratoryRequests | availability-calendar, request wizard, request-detail, review queue | RequestFacade, AvailabilityService | requester/reviewer guards |
| Inventories | lab-selector, item-list/editor/detail, location-tree, alerts, incidents, ledger, monthly-close | InventoryFacade, LocationStore | contextual laboratory permission guard |
| Publications | public-list/detail, notice-editor | PublicationsApi | editor guard; public routes unguarded |

Usar typed reactive forms, signals para estado local y un facade/store por feature. Evitar un store global con todas las entidades. El cliente generado desde OpenAPI reduce divergencia. Interceptors manejan correlation ID, auth, errores Problem Details y refresh/relogin.

## Estrategia de migración

### Principios

1. No big-bang ni copia mecánica de MVC.
2. Congelar comportamiento con pruebas de caracterización antes de cambiarlo.
3. Mantener inicialmente los esquemas existentes mediante repositories/ACL.
4. Una sola fuente de escritura por agregado durante cada etapa; evitar dual-write sin outbox/CDC.
5. Migrar vertical slices completos: UI Angular + API + dominio + persistencia + observabilidad.

### Roadmap técnico

| Fase | Resultado | Qué migrar/reutilizar | Criterio de salida |
|---|---|---|---|
| 0. Baseline | Entorno reproducible | Docker/Apache/PHP/MySQL, secretos, dumps anonimizados, OpenAPI inicial | CI ejecuta lint y pruebas; esquema versionado. |
| 1. Caracterización | Red de seguridad | Tests de rutas, SQL, permisos, archivos, PDFs y reglas temporales | Flujos críticos tienen golden master. |
| 2. Fundación | Laravel modular + Angular shell | IAM adapter, IDs, errors, audit, storage, outbox | Login shadow y health/observability en producción. |
| 3. Lecturas y Publicaciones | Primer slice productivo | Avisos públicos, catálogos y read APIs | Proxy enruta esas rutas al stack nuevo. |
| 4. Planificación | Catálogos Labs y horarios | Reutilizar tablas, reescribir lógica como aggregates/services | Disponibilidad nueva concuerda con legacy. |
| 5. Documentos Residencias | Upload/download/review/notificación | Migrar storage metadata; conservar archivos | Trazabilidad, permisos y hashes verificados. |
| 6. Solicitudes Labs | Workflow completo | Reescribir reglas y objetos Solicitud; no portar controllers | Concurrencia, conflictos y estados cubiertos. |
| 7. Residencias core | Proyecto, seguimiento y liberación | Migrar por expediente; ACL de Organización | Expedientes activos comparados y reconciliados. |
| 8. Inventarios | Items→ubicaciones→incidencias→cortes | Reescritura incremental; read model primero | Saldos/cortes reconciliados por laboratorio. |
| 9. Retiro | Apagar CI4 y adapters | Archivado inmutable, redirect final | Sin tráfico/escrituras legacy; rollback probado. |

### Convivencia temporal

- Apache/Nginx enruta por path: endpoints migrados a Laravel; resto a CodeIgniter.
- Angular puede abrir pantallas legacy mediante navegación completa durante transición; evitar iframe salvo emergencia.
- Laravel lee esquemas legacy mediante adapters. Cuando un agregado cambia de dueño, se hace corte de escritura y reconciliación.
- IAM puede iniciar con el mismo Entra ID; Laravel emite su propia sesión/API token y mapea al `idusuario_global`.
- PDF/Excel existentes pueden permanecer como servicio legacy temporal detrás de un port, pero deben validarse visualmente antes de sustituirse.

### Reutilizar vs reescribir

**Reutilizable:** lenguaje de negocio, esquema como fuente de descubrimiento, consultas de reportes validadas, plantillas visuales, algoritmos puros de RFC/CURP/fecha, objetos de Solicitud y assets institucionales.

**Reescribir:** OAuth/provisioning, autorización, God Controllers, manejo de archivos, transaction scripts de solicitudes/inventarios, servicios de correo duplicados y endpoints orientados a vistas.

## Recomendaciones finales

1. Rotar inmediatamente credenciales versionadas y documentar `OAuth.example.php`/variables de entorno sin secretos.
2. Obtener DDL y diccionario de las cuatro bases. El PNG recuperado confirma el diseño lógico histórico de `reposs` y `compartida`, pero no demuestra las claves foráneas, índices, triggers ni invariantes instalados actualmente.
3. Ejecutar tres sesiones de event storming: Residencias, Solicitudes Labs e Inventarios. Validar estados, excepciones, responsables y lenguaje.
4. Crear tests de caracterización alrededor de `OAuthGlobals`, ambos controladores de solicitudes, `Inventarios`, `Cortes`, `Documentos`, `Proyecto` y `Liberacion`.
5. Elegir modular monolith Laravel y gobernar dependencias con arquitectura tests; posponer microservicios.
6. Adoptar OpenAPI y Problem Details antes de Angular; diseñar contratos por caso de uso, no serializar Eloquent directamente.
7. Separar identidad de perfiles: `IdentityRef`, `EmployeeRef` y `ResidentRef` son conceptos distintos aunque hoy compartan IDs implícitos.
8. Introducir outbox y auditoría de actor/correlation ID para decisiones de validación, autorización, inventario y cierre.
9. Prohibir nuevas consultas cruzadas y nuevas reglas en controladores CI; todo cambio nuevo debería acercarse al límite objetivo.
10. Medir la migración por capacidades retiradas y reconciliación de datos, no por porcentaje de archivos traducidos.

## Decisiones de negocio incorporadas y validaciones técnicas pendientes

Las decisiones confirmadas se detallan en [Análisis de base de datos, apartado 13](ANALISIS_BASE_DATOS_MODERNIZACION.md#13-decisiones-confirmadas-con-negocio-y-operación). En particular: empresa global con múltiples asesores; un proyecto por residente/periodo con histórico; reglas 2/3+1 de reportes; reapertura con historial; snapshot inmutable en emisiones; puestos 0..n desde Microsoft; retención 2/5/10 sin borrado automático; producción institucional como fuente autoritativa; ausencia declarada de consumidores SQL externos y de triggers/procedimientos/vistas.

Persisten estas validaciones técnicas:

- Se proporcionó un diagrama PNG de `reposs` y `compartida`, ya incorporado al análisis complementario. Aún no se dispone del DDL físico completo ni de datos; las cardinalidades no verificables siguen marcadas como inferencias.
- No se pudo ejecutar PHP/PHPUnit porque el entorno no tenía PHP ni `vendor` instalados.
- Vistas fueron inventariadas y correlacionadas con controladores, pero el dominio se infirió prioritariamente desde commands, modelos, transacciones y rutas.
- Deben corroborarse en `information_schema` la ausencia de triggers, procedimientos y vistas, y mediante grants/logs la ausencia de consumidores SQL externos. También faltan correo real, permisos en Entra, volumen de archivos, tamaño de tablas y calidad de los datos.
