# Diseño API REST

## 1. Contrato y convenciones

- Base path: `/api/v1`.
- Contrato fuente: [api/openapi.yaml](api/openapi.yaml), OpenAPI 3.1.
- JSON de aplicación en `camelCase`; nombres físicos MySQL no se exponen.
- Fechas: `YYYY-MM-DD`; instantes: RFC 3339 UTC, por ejemplo `2026-09-06T18:10:00Z`.
- IDs: ULID string de 26 caracteres.
- Dinero/cantidades decimales: string decimal para evitar pérdida binaria.
- Lists usan cursor; `page[size]` por defecto 25, máximo 100, `page[after]` opaco.
- Filtros: `filter[field]`; orden: `sort=field,-otherField`; inclusión limitada: `include=` sólo si OpenAPI la declara.
- Todo request acepta `X-Correlation-ID`; el servidor devuelve el valor efectivo.
- Mutaciones sensibles usan `If-Match: "<version>"`; versión obsoleta produce `409`.
- Commands reintentables (`POST`) usan `Idempotency-Key` con alcance actor+ruta durante 24 horas.
- No se aceptan propiedades desconocidas en command DTO (`additionalProperties: false`).

## 2. Respuestas

Objeto individual:

```json
{
  "data": {
    "id": "01J00000000000000000000001",
    "version": 3,
    "createdAt": "2026-09-06T18:10:00Z"
  }
}
```

Colección paginada:

```json
{
  "data": [],
  "page": {
    "size": 25,
    "nextCursor": "opaque-or-null",
    "hasMore": false
  },
  "links": {
    "self": "/api/v1/notices?page[size]=25",
    "next": null
  }
}
```

No se devuelve `total` por defecto: contar tablas grandes encarece cada consulta. Los endpoints administrativos que realmente lo necesiten pueden declarar `estimatedTotal` o un endpoint de métricas separado.

## 3. Errores Problem Details

Media type: `application/problem+json`. Se implementa RFC 9457, que reemplaza RFC 7807 conservando su forma.

```json
{
  "type": "https://servicios.itsch.edu.mx/problems/validation-error",
  "title": "La solicitud no es válida",
  "status": 422,
  "detail": "Corrige los campos indicados.",
  "instance": "/api/v1/residencies/projects",
  "code": "VALIDATION_ERROR",
  "correlationId": "6f84a7d6-54ef-47cb-95ab-f0f53bfcab43",
  "errors": {
    "endDate": ["Debe ser posterior a startDate."]
  }
}
```

| HTTP | `code` base | Uso |
|---:|---|---|
| 400 | `MALFORMED_REQUEST` | JSON, cursor o parámetros imposibles de interpretar. |
| 401 | `AUTHENTICATION_REQUIRED` | Sin sesión o sesión expirada. |
| 403 | `FORBIDDEN` | Policy deniega; no revelar existencia cuando aplique. |
| 404 | `RESOURCE_NOT_FOUND` | Recurso inexistente o deliberadamente oculto. |
| 409 | `VERSION_CONFLICT`, `STATE_CONFLICT`, `SCHEDULE_CONFLICT` | Concurrencia o transición/invariante conflictiva. |
| 413 | `FILE_TOO_LARGE` | Upload fuera de límite. |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | MIME no permitido. |
| 422 | `VALIDATION_ERROR`, `BUSINESS_RULE_VIOLATION` | Forma válida, datos/regla inválidos. |
| 429 | `RATE_LIMITED` | Incluye `Retry-After`. |
| 500 | `INTERNAL_ERROR` | Mensaje genérico y correlationId; excepción sólo en logs. |
| 503 | `DEPENDENCY_UNAVAILABLE` | Entra, storage o DB indisponible; no fail-open. |

El catálogo de `type` vive en `/problems/{slug}` y es estable. Stack traces, SQL, rutas internas, claims y PII nunca aparecen en respuestas.

## 4. Autenticación, CSRF y versionado

- `/auth/entra/login`, `/auth/entra/callback` y `/auth/logout` son endpoints web de redirección, no JSON.
- Angular obtiene `/sanctum/csrf-cookie`, inicia login por navegación completa y luego consulta `/api/v1/me`.
- Rutas privadas usan `auth:sanctum`; requests mutables requieren cookie CSRF y `X-XSRF-TOKEN`.
- `/api/v1` es major contractual. Campos aditivos opcionales no crean v2; eliminar/renombrar/cambiar semántica sí.
- Toda deprecación devuelve `Deprecation: true`, `Sunset` y `Link` a la migración durante al menos dos releases.
- `info.version` del OpenAPI sigue SemVer; CI compara breaking changes contra la versión desplegada.

## 5. Catálogo por módulo

Las acciones son comandos del dominio; no se crean CRUD genéricos para tablas de historial, outbox o auditoría.

### 5.1 IAM

| Método y ruta | Request DTO | Response DTO | Permiso/política |
|---|---|---|---|
| `GET /me` | — | `CurrentIdentityResponse` | autenticado |
| `GET /iam/roles` | `RoleListQuery` | `Page<RoleResponse>` | `iam.roles.read` |
| `POST /iam/roles` | `CreateRoleRequest` | `RoleResponse` | `iam.roles.manage` |
| `PATCH /iam/roles/{roleId}` | `UpdateRoleRequest` | `RoleResponse` | `iam.roles.manage` + `If-Match` |
| `GET /iam/permissions` | `PermissionListQuery` | `Page<PermissionResponse>` | `iam.permissions.read` |
| `GET /iam/identities/{identityId}/roles` | — | `RoleAssignmentCollectionResponse` | self o `iam.roles.manage` |
| `POST /iam/identities/{identityId}/roles` | `GrantRoleRequest` | `RoleAssignmentResponse` | `iam.roles.grant` |
| `DELETE /iam/identities/{identityId}/roles/{roleId}` | `RevokeRoleRequest` | `204` | `iam.roles.revoke` |

`CurrentIdentityResponse`: `id`, `displayName`, `email`, `status`, `roles[]`, `permissions[]`, `scopes{laboratoryIds,residentId}`, `sessionExpiresAt`. Nunca devuelve tokens.

### 5.2 Organization

| Método y ruta | Request DTO | Response DTO | Permiso/política |
|---|---|---|---|
| `GET /organization/employees` | `EmployeeListQuery` | `Page<EmployeeSummaryResponse>` | `organization.employees.read` |
| `POST /organization/employees` | `CreateEmployeeRequest` | `EmployeeResponse` | `organization.employees.manage` |
| `GET /organization/employees/{employeeId}` | — | `EmployeeResponse` | self o policy |
| `PATCH /organization/employees/{employeeId}` | `UpdateEmployeeRequest` | `EmployeeResponse` | self limitado o manage |
| `GET /organization/advisers` | `AdviserSearchQuery` | `Page<EmployeeSummaryResponse>` | `residencies.advisers.assign` |
| `POST /organization/employees/{employeeId}/positions` | `AssignPositionRequest` | `PositionResponse` | `organization.positions.manage` |
| `POST /organization/employees/{employeeId}/positions/{positionId}/finish` | `FinishPositionRequest` | `PositionResponse` | manage + `If-Match` |
| `POST /organization/employees/{employeeId}/degrees` | `AddAcademicDegreeRequest` | `AcademicDegreeResponse` | manage |
| `GET /organization/units` | `OrganizationUnitListQuery` | `OrganizationTreeResponse` | autenticado |
| `POST /organization/units` | `CreateOrganizationUnitRequest` | `OrganizationUnitResponse` | `organization.units.manage` |

`AssignPositionRequest`: `organizationUnitId`, `positionCode?`, `positionName`, `startDate`, `source`, `sourceOrder?`. La sincronización interna puede además recibir `sourceText`; el endpoint público no acepta una cadena arbitraria de claims.

### 5.3 Residencies

| Método y ruta | Request DTO | Response DTO | Permiso/política |
|---|---|---|---|
| `GET /residencies/me` | — | `ResidentDashboardResponse` | residente vinculado |
| `PATCH /residencies/residents/{residentId}` | `CompleteResidentProfileRequest` | `ResidentResponse` | self o administración |
| `GET /residencies/programs` | `ProgramListQuery` | `Page<ProgramResponse>` | autenticado |
| `GET /residencies/periods` | `PeriodListQuery` | `Page<PeriodResponse>` | autenticado |
| `GET /residencies/companies` | `CompanySearchQuery` | `Page<CompanyResponse>` | autenticado |
| `POST /residencies/companies` | `CreateCompanyRequest` | `CompanyResponse` | `residencies.companies.create` |
| `PATCH /residencies/companies/{companyId}` | `UpdateCompanyRequest` | `CompanyResponse` | `residencies.companies.manage` |
| `POST /residencies/companies/{companyId}/contacts` | `AddCompanyContactRequest` | `CompanyContactResponse` | company policy |
| `POST /residencies/projects` | `CreateResidencyProjectRequest` | `ResidencyProjectResponse` | resident policy + idempotencia |
| `GET /residencies/projects/{projectId}` | — | `ResidencyProjectResponse` | project policy |
| `PATCH /residencies/projects/{projectId}` | `UpdateResidencyProjectRequest` | `ResidencyProjectResponse` | project policy + `If-Match` |
| `POST /residencies/projects/{projectId}/internal-advisers` | `AssignInternalAdviserRequest` | `AdviserAssignmentResponse` | project policy |
| `POST /residencies/projects/{projectId}/external-advisers` | `AssignExternalAdviserRequest` | `AdviserAssignmentResponse` | project policy |
| `GET /residencies/files/{residentId}/requirements` | — | `RequirementStatusCollectionResponse` | resident/reviewer policy |
| `POST /residencies/evidence` | `SubmitEvidenceRequest` | `EvidenceSubmissionResponse` | resident policy + idempotencia |
| `GET /residencies/evidence/{evidenceId}/history` | — | `EvidenceHistoryResponse` | resident/reviewer policy |
| `GET /residencies/review-queue` | `EvidenceQueueQuery` | `Page<EvidenceSubmissionResponse>` | `residencias.evidencias.revisar` |
| `POST /residencies/evidence/{evidenceId}/reviews` | `ReviewEvidenceRequest` | `EvidenceReviewResponse` | review policy + idempotencia |
| `POST /residencies/projects/{projectId}/reports` | `SubmitProgressReportRequest` | `ProgressReportResponse` | project policy |
| `GET /residencies/projects/{projectId}/report-plan` | — | `ReportPlanResponse` | project policy |
| `POST /residencies/projects/{projectId}/release/resolve` | `ResolveReleaseRequest` | `ReleaseResponse` | `residencias.liberaciones.resolver` |
| `POST /residencies/projects/{projectId}/release/reopen` | `ReopenReleaseRequest` | `ReleaseResponse` | release policy + idempotencia |
| `GET /residencies/projects/{projectId}/release/history` | — | `ReleaseHistoryResponse` | release policy |
| `POST /residencies/projects/{projectId}/documents` | `IssueDocumentRequest` | `202 IssuedDocumentJobResponse` | document policy + idempotencia |
| `GET /residencies/issued-documents/{documentId}` | — | `IssuedDocumentResponse` | document policy |

DTO críticos:

- `CreateResidencyProjectRequest`: `residentId`, `periodId`, `companyId`, `programId`, `name`, `startDate`, `endDate`, asesores opcionales. El handler bloquea proyectos del residente y verifica solapamiento.
- `SubmitEvidenceRequest`: `requirementId`, `residentId`, `projectId?`, `stagedFileId`; la versión la asigna el servidor.
- `ReviewEvidenceRequest`: `decision`, `observations?`, `grade?`, `expectedReviewNumber`; el servidor conserva anterior/nuevo.
- `SubmitProgressReportRequest`: `type`, `sequence`, `evidenceId`; cantidades permitidas provienen del periodo.
- `ResolveReleaseRequest`/`ReopenReleaseRequest`: `decision`, `resolution?`, `observations`, `expectedVersion`.
- `IssueDocumentRequest`: `type`, `templateId`, `expectedTemplateVersion`; el servidor construye el snapshot desde consultas aprobadas, no acepta `snapshot` del cliente.
- `IssuedDocumentResponse`: metadata, `snapshotHash`, `issuedAt`, `status`, enlace de descarga de corta duración; nunca permite `PATCH` del snapshot.

### 5.4 Publications

| Método y ruta | Request DTO | Response DTO | Permiso/política |
|---|---|---|---|
| `GET /public/notices` | `PublicNoticeListQuery` | `Page<NoticeResponse>` | pública, sólo vigentes |
| `GET /public/notices/{noticeId}` | — | `NoticeResponse` | pública si publicado/vigente |
| `GET /publications/notices` | `AdministrativeNoticeListQuery` | `Page<NoticeResponse>` | `publicaciones.administrar` |
| `POST /publications/notices` | `CreateNoticeRequest` | `NoticeResponse` | administrar |
| `PATCH /publications/notices/{noticeId}` | `UpdateNoticeRequest` | `NoticeResponse` | policy + `If-Match` |
| `POST /publications/notices/{noticeId}/publish` | `PublishNoticeRequest` | `NoticeResponse` | policy |
| `POST /publications/notices/{noticeId}/withdraw` | `WithdrawNoticeRequest` | `NoticeResponse` | policy |

`NoticeResponse`: `id`, `type`, `title`, `description`, `requirements`, `url?`, `publicationWindow`, `status`, recursos seguros y `version`.

### 5.5 AcademicPlanning

| Método y ruta | Request DTO | Response DTO | Permiso/política |
|---|---|---|---|
| `GET /academic-planning/semesters` | `SemesterListQuery` | `Page<SemesterResponse>` | autenticado |
| `POST /academic-planning/semesters` | `CreateSemesterRequest` | `SemesterResponse` | `planning.manage` |
| `POST /academic-planning/semesters/{semesterId}/activate` | `ActivateSemesterRequest` | `SemesterResponse` | policy |
| `GET /academic-planning/laboratories` | `LaboratoryListQuery` | `Page<LaboratoryResponse>` | autenticado |
| `POST /academic-planning/laboratories` | `ConfigureLaboratoryRequest` | `LaboratoryResponse` | `planning.manage` |
| `PATCH /academic-planning/laboratories/{laboratoryId}` | `ConfigureLaboratoryRequest` | `LaboratoryResponse` | policy + `If-Match` |
| `GET /academic-planning/laboratories/{laboratoryId}/schedules` | `ScheduleListQuery` | `ScheduleCollectionResponse` | autenticado |
| `POST /academic-planning/laboratories/{laboratoryId}/schedules` | `PublishScheduleRequest` | `ScheduleResponse` | policy |
| `GET /academic-planning/laboratories/{laboratoryId}/availability` | `AvailabilityQuery` | `AvailabilityResponse` | autenticado |
| `GET /academic-planning/holidays` | `HolidayListQuery` | `Page<HolidayResponse>` | autenticado |
| `POST /academic-planning/holidays` | `RegisterHolidayRequest` | `HolidayResponse` | `planning.manage` |
| `GET /academic-planning/catalogs/{catalog}` | `CatalogQuery` | `Page<CatalogItemResponse>` | autenticado |
| `POST /academic-planning/catalogs/{catalog}` | `UpsertCatalogItemRequest` | `CatalogItemResponse` | `planning.catalogs.manage` |

`catalog` sólo admite `careers`, `specialties`, `subjects`, `curricula`, `groups`; no se interpola como nombre de tabla.

### 5.6 LaboratoryRequests

| Método y ruta | Request DTO | Response DTO | Permiso/política |
|---|---|---|---|
| `POST /laboratory-requests/availability-quotes` | `QuoteAvailabilityRequest` | `AvailabilityQuoteResponse` | autenticado |
| `GET /laboratory-requests` | `LaboratoryRequestListQuery` | `Page<LaboratoryRequestResponse>` | policy filtra alcance |
| `POST /laboratory-requests` | `CreateLaboratoryRequestRequest` | `LaboratoryRequestResponse` | solicitante + idempotencia |
| `GET /laboratory-requests/{requestId}` | — | `LaboratoryRequestResponse` | request policy |
| `PATCH /laboratory-requests/{requestId}` | `UpdateDraftLaboratoryRequestRequest` | `LaboratoryRequestResponse` | owner + borrador + `If-Match` |
| `POST /laboratory-requests/{requestId}/submit` | `SubmitLaboratoryRequestRequest` | `LaboratoryRequestResponse` | owner |
| `POST /laboratory-requests/{requestId}/decisions` | `DecideLaboratoryRequestRequest` | `LaboratoryRequestResponse` | reviewer + idempotencia |
| `POST /laboratory-requests/{requestId}/cancel` | `CancelLaboratoryRequestRequest` | `LaboratoryRequestResponse` | owner/reviewer según estado |
| `GET /laboratory-requests/review-queue` | `RequestReviewQueueQuery` | `Page<LaboratoryRequestResponse>` | reviewer contextual |
| `GET /laboratory-requests/calendar` | `OccupancyCalendarQuery` | `CalendarEventCollectionResponse` | autenticado |
| `GET /laboratory-requests/{requestId}/receipt` | — | PDF/metadata | request policy |

`CreateLaboratoryRequestRequest`: discriminador `type` y exactamente un objeto `practice`, `various` o `extraordinary`, más `laboratoryId`, `startAt`, `endAt`, `purpose`. El servidor vuelve a comprobar conflicto al autorizar dentro del lock; una cotización nunca reserva.

### 5.7 Inventories

| Método y ruta | Request DTO | Response DTO | Permiso/política |
|---|---|---|---|
| `GET /inventories` | `InventoryListQuery` | `Page<InventoryResponse>` | permisos contextuales |
| `POST /inventories` | `InitializeInventoryRequest` | `InventoryResponse` | `inventarios.administrar` |
| `GET /inventories/{inventoryId}/dashboard` | — | `InventoryDashboardResponse` | inventory policy |
| `GET /inventories/{inventoryId}/items` | `InventoryItemListQuery` | `Page<InventoryItemResponse>` | inventory policy |
| `POST /inventories/{inventoryId}/items` | `RegisterInventoryItemRequest` | `InventoryItemResponse` | item policy + idempotencia |
| `GET /inventories/{inventoryId}/items/{itemId}` | — | `InventoryItemResponse` | item policy |
| `PATCH /inventories/{inventoryId}/items/{itemId}` | `UpdateInventoryItemRequest` | `InventoryItemResponse` | item policy + `If-Match` |
| `POST /inventories/{inventoryId}/items/{itemId}/movements` | `RegisterMovementRequest` | `MovementResponse` | item policy + idempotencia |
| `POST /inventories/{inventoryId}/items/{itemId}/retire` | `RetireItemRequest` | `InventoryItemResponse` | item policy |
| `GET /inventories/{inventoryId}/items/{itemId}/ledger` | `LedgerQuery` | `Page<MovementResponse>` | inventory policy |
| `GET /inventories/{inventoryId}/locations` | — | `LocationTreeResponse` | inventory policy |
| `POST /inventories/{inventoryId}/locations` | `CreateLocationRequest` | `LocationResponse` | `inventarios.administrar` |
| `POST /inventories/{inventoryId}/incidents` | `ReportIncidentRequest` | `IncidentResponse` | inventory policy |
| `POST /inventories/{inventoryId}/incidents/{incidentId}/resolve` | `ResolveIncidentRequest` | `IncidentResponse` | inventory policy |
| `GET /inventories/{inventoryId}/alerts` | `AlertListQuery` | `Page<AlertResponse>` | inventory policy |
| `PUT /inventories/{inventoryId}/abc-policy` | `ConfigureAbcPolicyRequest` | `AbcPolicyResponse` | administrar + `If-Match` |
| `POST /inventories/{inventoryId}/monthly-closes` | `GenerateMonthlyCloseRequest` | `202 MonthlyCloseJobResponse` | administrar + idempotencia |
| `GET /inventories/{inventoryId}/monthly-closes/{period}` | — | `MonthlyCloseResponse` | inventory policy |
| `POST /inventories/{inventoryId}/exports` | `CreateInventoryExportRequest` | `202 ExportJobResponse` | inventory policy |

`RegisterInventoryItemRequest`: `type` y exactamente un bloque `equipment`, `material`, `reagent`, `software` o `infrastructure`; campos comunes incluyen `code`, `name`, `locationId`, `quantity`, `unit`, `status`. Responses devuelven un bloque detalle discriminado y nunca las cinco tablas internas.

### 5.8 Shared/operación

| Método y ruta | Request DTO | Response DTO | Acceso |
|---|---|---|---|
| `POST /files/staged` | multipart `file`, `purpose` | `StagedFileResponse` | autenticado + rate/size limit |
| `GET /files/{fileId}/download` | — | stream o 302 firmado | policy del recurso propietario |
| `GET /health/live` | — | `HealthResponse` | red interna, sin dependencias |
| `GET /health/ready` | — | `ReadinessResponse` | red interna; DB/storage/outbox worker |
| `GET /operations/audit-records` | `AuditRecordQuery` | `Page<AuditRecordResponse>` | `auditoria.consultar` |
| `POST /operations/retention/dry-runs` | `RetentionDryRunRequest` | `202 RetentionJobResponse` | `retencion.administrar` |
| `POST /operations/retention/purges` | `RetentionPurgeRequest` | `202 RetentionJobResponse` | aprobación reforzada + idempotencia |

No existen endpoints para editar outbox, historiales o snapshots. Las operaciones de retención requieren `dryRunId`, motivo, alcance exacto y segundo control de autorización.

## 6. Mapeo DTO → capa

```text
JSON
  → FormRequest valida forma
  → *RequestData inmutable
  → Command/Query
  → Handler
  → Domain Value Objects
  → *ResultData inmutable
  → API Resource
  → JSON documentado
```

No se usa `Model::create($request->all())`, no se serializa Eloquent y no se pasan arrays sin tipo entre Presentation y Application. Los DTO son `final readonly` y se construyen con campos explícitos.

## 7. Gate API First

Una PR que añade/cambia endpoint debe, en este orden:

1. modificar OpenAPI y ejemplos;
2. pasar lint y comparación de breaking changes;
3. regenerar el cliente Angular en un artefacto reproducible;
4. añadir tests de contrato backend;
5. implementar handler/policy;
6. consumir desde facade Angular sin editar a mano el cliente generado.

Fuente de errores: [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457.html). Fuente del contrato: [OpenAPI 3.1](https://spec.openapis.org/oas/v3.1.1.html).
