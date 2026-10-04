# Arquitectura ejecutable Laravel

## 1. Unidad de construcción y despliegue

El backend nuevo es una aplicación Laravel independiente del árbol CodeIgniter actual. La raíz indicada abajo es la raíz del proyecto backend, no la carpeta `app/` legacy.

```text
backend/
├── app/
│   ├── Console/Commands/
│   ├── Http/Middleware/
│   ├── Modules/
│   └── Providers/ModulesServiceProvider.php
├── bootstrap/providers.php
├── config/modules.php
├── database/migrations/
├── routes/api.php
├── tests/Architecture/
├── tests/Contract/
└── tests/Support/
```

Apache publica únicamente `backend/public`. Angular se entrega como artefacto estático separado. Durante convivencia, el reverse proxy enruta `/api/v1/*` y `/auth/*` a Laravel y conserva las rutas no migradas en CodeIgniter.

## 2. Estructura obligatoria de cada módulo

Todos los módulos usan la misma estructura física; una carpeta vacía no se crea hasta que exista una clase real.

```text
app/Modules/<Module>/
├── Domain/
│   ├── Aggregates/
│   ├── Entities/
│   ├── ValueObjects/
│   ├── Events/
│   ├── Policies/
│   ├── Services/
│   ├── Exceptions/
│   └── Contracts/
├── Application/
│   ├── Commands/<UseCase>/{Command.php,Handler.php}
│   ├── Queries/<UseCase>/{Query.php,Handler.php}
│   ├── DTO/
│   ├── Ports/
│   └── Projectors/
├── Infrastructure/
│   ├── Persistence/Eloquent/{Models,Repositories,Mappers}/
│   ├── Persistence/Migrations/
│   ├── Persistence/ReadModels/
│   ├── ExternalAdapters/
│   ├── Jobs/
│   └── Providers/<Module>ServiceProvider.php
├── Presentation/
│   ├── Http/Controllers/Api/V1/
│   ├── Http/Requests/Api/V1/
│   ├── Http/Resources/Api/V1/
│   ├── Http/Policies/
│   └── Routes/api_v1.php
└── Tests/{Unit,Integration,Feature,Contract}/
```

Reglas mecánicas:

- `Domain` es PHP puro: no importa `Illuminate`, Eloquent, HTTP ni SDK externos.
- `Application` depende de Domain y de ports propios; no de controllers ni adapters.
- `Infrastructure` implementa contratos y contiene Eloquent, SQL, filesystem, Entra, PDF, correo y legacy ACL.
- `Presentation` traduce HTTP ↔ command/query DTO; no inicia transacciones ni contiene reglas.
- El service provider de cada módulo registra bindings, rutas, policies y migraciones propias.
- Las migraciones se cargan desde `Infrastructure/Persistence/Migrations`; el SQL baseline es referencia de aceptación y se descompone en migraciones ordenadas, no se ejecuta además de ellas.
- Un repositorio reconstruye un agregado; no retorna `Builder` ni modelos Eloquent.

## 3. Carpetas y componentes por módulo

### 3.1 IAM

```text
app/Modules/IAM/
├── Domain/
│   ├── Aggregates/Identity.php
│   ├── Entities/{ExternalAccount,Role,Permission,RoleAssignment}.php
│   ├── ValueObjects/{IdentityId,TenantId,EntraObjectId,NormalizedEmail}.php
│   ├── Events/{InstitutionalIdentityLinked,RoleGranted,RoleRevoked}.php
│   ├── Policies/ProvisioningPolicy.php
│   ├── Exceptions/{IdentityDisabled,InvalidExternalIdentity}.php
│   └── Contracts/{IdentityRepository,AuthorizationRepository}.php
├── Application/
│   ├── Commands/{HandleEntraCallback,ProvisionIdentity,GrantRole,RevokeRole,Logout}/
│   ├── Queries/{GetCurrentIdentity,ListRoles,ListPermissions,ListEffectivePermissions}/
│   ├── DTO/{CurrentIdentityData,RoleData,PermissionData}.php
│   └── Ports/{ExternalIdentityProvider,SessionManager,ProvisioningPublisher}.php
├── Infrastructure/
│   ├── Persistence/Eloquent/{Models,Repositories,Mappers}/
│   ├── Persistence/Migrations/
│   ├── ExternalAdapters/{EntraOidcClient,MicrosoftGraphProfileClient,LegacyRbacAdapter}/
│   ├── Jobs/RefreshProvisioningProjection.php
│   └── Providers/IAMServiceProvider.php
└── Presentation/
    ├── Http/Controllers/Api/V1/{CurrentIdentityController,RoleController,RoleAssignmentController}.php
    ├── Http/Controllers/Auth/{EntraLoginController,EntraCallbackController,LogoutController}.php
    ├── Http/Requests/Api/V1/{GrantRoleRequest,RevokeRoleRequest}.php
    ├── Http/Resources/Api/V1/{CurrentIdentityResource,RoleResource,PermissionResource}.php
    ├── Http/Policies/{RolePolicy,PermissionPolicy}.php
    └── Routes/{api_v1.php,web_auth.php}
```

### 3.2 Organization

```text
app/Modules/Organization/
├── Domain/
│   ├── Aggregates/{Employee,OrganizationTree}.php
│   ├── Entities/{PositionAssignment,AcademicDegree,OrganizationUnit}.php
│   ├── ValueObjects/{EmployeeId,OrganizationUnitId,PositionName}.php
│   ├── Events/{EmployeeRegistered,PositionAssigned,PositionFinished,ProfileSynchronized}.php
│   ├── Services/JobTitleParser.php
│   ├── Exceptions/{InvalidPositionChain,OrganizationCycle}.php
│   └── Contracts/{EmployeeRepository,OrganizationUnitRepository}.php
├── Application/
│   ├── Commands/{RegisterEmployee,SynchronizeEntraProfile,AssignPosition,FinishPosition,AddAcademicDegree}/
│   ├── Queries/{GetEmployee,ListEmployees,SearchAdvisers,GetOrganizationTree}/
│   ├── DTO/{EmployeeData,PositionData,AcademicDegreeData,OrganizationUnitData}.php
│   └── Ports/{IdentityDirectory,OrganizationProjectionPublisher}.php
├── Infrastructure/
│   ├── Persistence/Eloquent/{Models,Repositories,Mappers}/
│   ├── Persistence/Migrations/
│   ├── Persistence/ReadModels/EmployeeSearchReadModel.php
│   ├── ExternalAdapters/{IAMIdentityDirectory,LegacyEmployeeDirectory}/
│   └── Providers/OrganizationServiceProvider.php
└── Presentation/
    ├── Http/Controllers/Api/V1/{EmployeeController,EmployeePositionController,EmployeeDegreeController,OrganizationUnitController}.php
    ├── Http/Requests/Api/V1/{UpdateEmployeeRequest,AssignPositionRequest,FinishPositionRequest,AddDegreeRequest}.php
    ├── Http/Resources/Api/V1/{EmployeeResource,EmployeeSummaryResource,PositionResource,DegreeResource,OrganizationUnitResource}.php
    ├── Http/Policies/{EmployeePolicy,OrganizationUnitPolicy}.php
    └── Routes/api_v1.php
```

### 3.3 Residencies

```text
app/Modules/Residencies/
├── Domain/
│   ├── Aggregates/{ResidentFile,ResidencyProject,Company,EvidenceSubmission,Release}.php
│   ├── Entities/{CompanyContact,InternalAdviserAssignment,ExternalAdviserAssignment,EvidenceReview,ProgressReport,IssuedDocument,Certificate}.php
│   ├── ValueObjects/{ResidentId,ControlNumber,Period,ReportPlan,DocumentSnapshot,Folio}.php
│   ├── Events/{ResidentRegistered,ProjectCreated,EvidenceSubmitted,EvidenceReviewed,ReportSubmitted,ReleaseResolved,ReleaseReopened,DocumentIssued}.php
│   ├── Policies/{ReportPlanPolicy,ReleaseEligibilityPolicy,ProjectOverlapPolicy}.php
│   ├── Services/{DocumentSnapshotBuilder,FolioGenerator}.php
│   ├── Exceptions/{OverlappingProject,IncompleteReportPlan,IneligibleRelease}.php
│   └── Contracts/{ResidentFileRepository,ProjectRepository,CompanyRepository,EvidenceRepository,ReleaseRepository}.php
├── Application/
│   ├── Commands/{CompleteResidentProfile,RegisterCompany,AddCompanyContact,CreateProject,AssignInternalAdviser,AssignExternalAdviser,SubmitEvidence,ReviewEvidence,SubmitProgressReport,ResolveRelease,ReopenRelease,IssueDocument}/
│   ├── Queries/{GetResidentDashboard,GetResidentFile,ListCompanies,GetProject,ListEvidenceQueue,GetEvidenceHistory,GetReleaseHistory,DownloadIssuedDocument}/
│   ├── DTO/{ResidentData,CompanyData,ProjectData,EvidenceData,ReviewData,ReportData,ReleaseData,IssuedDocumentData}.php
│   └── Ports/{EmployeeDirectory,FileStorage,DocumentRenderer,Notifier}.php
├── Infrastructure/
│   ├── Persistence/Eloquent/{Models,Repositories,Mappers}/
│   ├── Persistence/Migrations/
│   ├── Persistence/ReadModels/{ResidentDashboardReadModel,EvidenceQueueReadModel}.php
│   ├── ExternalAdapters/{OrganizationEmployeeDirectory,LegacyResidenciesRepository,DompdfDocumentRenderer}/
│   ├── Jobs/{RenderIssuedDocument,NotifyEvidenceDecision}.php
│   └── Providers/ResidenciesServiceProvider.php
└── Presentation/
    ├── Http/Controllers/Api/V1/{ResidentController,CompanyController,CompanyContactController,ProjectController,ProjectAdviserController,EvidenceController,EvidenceReviewController,ProgressReportController,ReleaseController,IssuedDocumentController}.php
    ├── Http/Requests/Api/V1/{CompleteResidentProfileRequest,CreateCompanyRequest,CreateProjectRequest,AssignAdviserRequest,SubmitEvidenceRequest,ReviewEvidenceRequest,SubmitReportRequest,ResolveReleaseRequest,IssueDocumentRequest}.php
    ├── Http/Resources/Api/V1/{ResidentResource,CompanyResource,ProjectResource,EvidenceResource,ReviewResource,ProgressReportResource,ReleaseResource,IssuedDocumentResource}.php
    ├── Http/Policies/{ResidentFilePolicy,CompanyPolicy,ProjectPolicy,EvidencePolicy,ReviewPolicy,ReleasePolicy}.php
    └── Routes/api_v1.php
```

### 3.4 Publications

```text
app/Modules/Publications/
├── Domain/
│   ├── Aggregates/Notice.php
│   ├── Entities/NoticeResource.php
│   ├── ValueObjects/{NoticeId,PublicationWindow}.php
│   ├── Events/{NoticePublished,NoticeWithdrawn}.php
│   ├── Exceptions/InvalidPublicationWindow.php
│   └── Contracts/NoticeRepository.php
├── Application/
│   ├── Commands/{CreateNotice,UpdateNotice,PublishNotice,WithdrawNotice}/
│   ├── Queries/{ListPublicNotices,GetPublicNotice,ListAdministrativeNotices}/
│   ├── DTO/NoticeData.php
│   └── Ports/{FileStorage,NoticeProjectionPublisher}.php
├── Infrastructure/
│   ├── Persistence/Eloquent/{Models,Repositories,Mappers}/
│   ├── Persistence/Migrations/
│   ├── Persistence/ReadModels/PublicNoticeReadModel.php
│   └── Providers/PublicationsServiceProvider.php
└── Presentation/
    ├── Http/Controllers/Api/V1/{PublicNoticeController,NoticeController}.php
    ├── Http/Requests/Api/V1/{CreateNoticeRequest,UpdateNoticeRequest,PublicationWindowRequest}.php
    ├── Http/Resources/Api/V1/NoticeResource.php
    ├── Http/Policies/NoticePolicy.php
    └── Routes/api_v1.php
```

### 3.5 AcademicPlanning

```text
app/Modules/AcademicPlanning/
├── Domain/
│   ├── Aggregates/{Semester,LaboratorySchedule,Curriculum}.php
│   ├── Entities/{Laboratory,ScheduleSlot,Holiday,Career,Specialty,Subject,CoursePlan,Group}.php
│   ├── ValueObjects/{LaboratoryId,DateRange,TimeRange,AcademicPeriod}.php
│   ├── Events/{SemesterActivated,LaboratoryConfigured,SchedulePublished}.php
│   ├── Policies/{SemesterActivationPolicy,ScheduleConflictPolicy}.php
│   ├── Exceptions/{OverlappingSchedule,InvalidAcademicPeriod}.php
│   └── Contracts/{SemesterRepository,LaboratoryRepository,ScheduleRepository,CurriculumRepository}.php
├── Application/
│   ├── Commands/{CreateSemester,ActivateSemester,ConfigureLaboratory,PublishSchedule,RegisterHoliday,MaintainCurriculum}/
│   ├── Queries/{ListSemesters,ListLaboratories,GetLaboratoryAvailability,ListCurriculumCatalogs}/
│   ├── DTO/{SemesterData,LaboratoryData,ScheduleData,AvailabilityData,CurriculumData}.php
│   └── Ports/{EmployeeDirectory,AvailabilityProjectionPublisher}.php
├── Infrastructure/
│   ├── Persistence/Eloquent/{Models,Repositories,Mappers}/
│   ├── Persistence/Migrations/
│   ├── Persistence/ReadModels/LaboratoryAvailabilityReadModel.php
│   ├── ExternalAdapters/{OrganizationEmployeeDirectory,LegacyLaboratoryCatalog}/
│   └── Providers/AcademicPlanningServiceProvider.php
└── Presentation/
    ├── Http/Controllers/Api/V1/{SemesterController,LaboratoryController,ScheduleController,HolidayController,CurriculumController,AvailabilityController}.php
    ├── Http/Requests/Api/V1/{CreateSemesterRequest,ConfigureLaboratoryRequest,PublishScheduleRequest,RegisterHolidayRequest}.php
    ├── Http/Resources/Api/V1/{SemesterResource,LaboratoryResource,ScheduleResource,HolidayResource,AvailabilityResource}.php
    ├── Http/Policies/{PlanningPolicy,LaboratoryPolicy}.php
    └── Routes/api_v1.php
```

### 3.6 LaboratoryRequests

```text
app/Modules/LaboratoryRequests/
├── Domain/
│   ├── Aggregates/LaboratoryRequest.php
│   ├── Entities/{PracticeDetail,VariousDetail,ExtraordinaryDetail,RequestDecision}.php
│   ├── ValueObjects/{LaboratoryRequestId,ReservationInterval,RequestType}.php
│   ├── Events/{LaboratoryRequestCreated,RequestAuthorized,RequestRejected,RequestCancelled}.php
│   ├── Policies/{AvailabilityPolicy,RequestTransitionPolicy}.php
│   ├── Services/ConflictDetector.php
│   ├── Exceptions/{ReservationConflict,InvalidRequestTransition}.php
│   └── Contracts/LaboratoryRequestRepository.php
├── Application/
│   ├── Commands/{CreateLaboratoryRequest,SubmitLaboratoryRequest,AuthorizeLaboratoryRequest,RejectLaboratoryRequest,CancelLaboratoryRequest}/
│   ├── Queries/{QuoteAvailability,ListMyRequests,ListReviewQueue,GetLaboratoryRequest,GetOccupancyCalendar}/
│   ├── DTO/{LaboratoryRequestData,RequestDecisionData,CalendarEventData}.php
│   └── Ports/{PlanningAvailabilityGateway,EmployeeDirectory,DocumentRenderer,Notifier}.php
├── Infrastructure/
│   ├── Persistence/Eloquent/{Models,Repositories,Mappers}/
│   ├── Persistence/Migrations/
│   ├── Persistence/ReadModels/{RequestQueueReadModel,OccupancyCalendarReadModel}.php
│   ├── ExternalAdapters/{AcademicPlanningGateway,LegacyRequestRepository}/
│   ├── Jobs/{RenderRequestReceipt,NotifyRequestDecision}.php
│   └── Providers/LaboratoryRequestsServiceProvider.php
└── Presentation/
    ├── Http/Controllers/Api/V1/{AvailabilityQuoteController,LaboratoryRequestController,RequestDecisionController,OccupancyCalendarController}.php
    ├── Http/Requests/Api/V1/{QuoteAvailabilityRequest,CreateLaboratoryRequestRequest,DecideLaboratoryRequestRequest}.php
    ├── Http/Resources/Api/V1/{LaboratoryRequestResource,RequestDecisionResource,CalendarEventResource}.php
    ├── Http/Policies/LaboratoryRequestPolicy.php
    └── Routes/api_v1.php
```

### 3.7 Inventories

```text
app/Modules/Inventories/
├── Domain/
│   ├── Aggregates/{Inventory,InventoryItem,LocationTree,MonthlyClose}.php
│   ├── Entities/{EquipmentDetail,MaterialDetail,ReagentDetail,SoftwareDetail,InfrastructureDetail,Movement,Incident,MonthlyCloseLine,AbcPolicy}.php
│   ├── ValueObjects/{InventoryId,ItemId,LocationId,Quantity,Money,InventoryPeriod}.php
│   ├── Events/{InventoryInitialized,ItemRegistered,ItemRelocated,StockAdjusted,ItemRetired,IncidentReported,MonthlyCloseGenerated}.php
│   ├── Policies/{ItemTypePolicy,LocationPolicy,MonthlyClosePolicy}.php
│   ├── Services/{InventoryValuation,AbcClassifier}.php
│   ├── Exceptions/{InvalidItemDetail,NegativeStock,LocationCycle,ClosedPeriod}.php
│   └── Contracts/{InventoryRepository,ItemRepository,LocationRepository,IncidentRepository,MonthlyCloseRepository}.php
├── Application/
│   ├── Commands/{InitializeInventory,RegisterItem,UpdateItem,MoveItem,AdjustStock,RetireItem,ReportIncident,ResolveIncident,ConfigureAbcPolicy,GenerateMonthlyClose}/
│   ├── Queries/{GetInventoryDashboard,ListItems,GetItemLedger,GetLocationTree,ListAlerts,ListIncidents,GetMonthlyClose,ExportInventory}/
│   ├── DTO/{InventoryData,InventoryItemData,MovementData,IncidentData,AlertData,MonthlyCloseData}.php
│   └── Ports/{LaboratoryCatalog,EmployeeDirectory,FileStorage,SpreadsheetExporter,Notifier}.php
├── Infrastructure/
│   ├── Persistence/Eloquent/{Models,Repositories,Mappers}/
│   ├── Persistence/Migrations/
│   ├── Persistence/ReadModels/{InventoryDashboardReadModel,InventoryAlertReadModel,ItemLedgerReadModel}.php
│   ├── ExternalAdapters/{AcademicPlanningLaboratoryCatalog,LegacyInventoryRepository,PhpSpreadsheetExporter}/
│   ├── Jobs/{DetectInventoryAlerts,BuildInventoryExport}.php
│   └── Providers/InventoriesServiceProvider.php
└── Presentation/
    ├── Http/Controllers/Api/V1/{InventoryController,InventoryItemController,InventoryMovementController,LocationController,IncidentController,AlertController,AbcPolicyController,MonthlyCloseController,InventoryExportController}.php
    ├── Http/Requests/Api/V1/{RegisterItemRequest,UpdateItemRequest,MoveItemRequest,AdjustStockRequest,ReportIncidentRequest,ResolveIncidentRequest,ConfigureAbcPolicyRequest,GenerateMonthlyCloseRequest}.php
    ├── Http/Resources/Api/V1/{InventoryResource,InventoryItemResource,MovementResource,LocationResource,IncidentResource,AlertResource,MonthlyCloseResource}.php
    ├── Http/Policies/{InventoryPolicy,InventoryItemPolicy,MonthlyClosePolicy}.php
    └── Routes/api_v1.php
```

### 3.8 Shared

`Shared` contiene capacidades técnicas estables, no entidades de negocio compartidas.

```text
app/Modules/Shared/
├── Domain/
│   ├── ValueObjects/{Ulid,Email,DateRange,CorrelationId}.php
│   ├── Events/DomainEvent.php
│   ├── Exceptions/{DomainException,ConcurrencyException}.php
│   └── Contracts/{Clock,TransactionManager}.php
├── Application/
│   ├── Bus/{CommandBus,QueryBus,EventBus}.php
│   ├── DTO/{PageRequest,PageResult,ProblemDetails}.php
│   └── Ports/{FileStorage,MalwareScanner,AuditRecorder,Outbox,Notifier}.php
├── Infrastructure/
│   ├── Bus/{LaravelCommandBus,LaravelQueryBus,OutboxEventBus}.php
│   ├── Persistence/{LaravelTransactionManager,OutboxRepository,AuditRepository}.php
│   ├── Storage/{LaravelFileStorage,ClamAvMalwareScanner}.php
│   ├── Observability/{CorrelationContext,Telemetry}.php
│   ├── Jobs/{PublishOutboxBatch,ApplyRetentionPolicy}.php
│   └── Providers/SharedServiceProvider.php
└── Presentation/
    ├── Http/Middleware/{CorrelationIdMiddleware,ProblemDetailsMiddleware,IdempotencyMiddleware}.php
    ├── Http/Controllers/{HealthController,FileDownloadController}.php
    └── Routes/{api_v1.php,health.php}
```

## 4. Dependencias permitidas

| Consumidor | Puede usar directamente | Acceso a otros módulos |
|---|---|---|
| Todos | Shared Domain/Application | Ports, DTO publicados o eventos; nunca Eloquent. |
| Organization | IAM published `IdentityRef` | `IdentityDirectory`. |
| Residencies | IAM, Organization, Shared | `IdentityRef`, `EmployeeDirectory`, storage/notificación. |
| AcademicPlanning | IAM, Organization, Shared | responsable mediante `EmployeeRef`. |
| LaboratoryRequests | IAM, Organization, AcademicPlanning, Shared | gateways de identidad, empleado y disponibilidad. |
| Inventories | IAM, Organization, AcademicPlanning, Shared | referencias/proyecciones de laboratorio y responsables. |
| Publications | IAM, Shared | identidad publicadora y storage. |

Se prohíben ciclos. Un test en `tests/Architecture/ModuleDependenciesTest.php` inspecciona namespaces y falla si Domain importa `Illuminate` o si un módulo importa `Infrastructure`/Eloquent ajeno.

## 5. Flujo de un comando

```text
HTTP Request
  → FormRequest (forma/tipos)
  → Request DTO / Command
  → Policy (actor + recurso/alcance)
  → Handler
  → TransactionManager
      → Repository bloquea/carga agregado
      → método de dominio valida y muta
      → Repository persiste
      → Outbox persiste eventos
      → AuditRecorder registra acción crítica
  → commit
  → Resource / 201|200|204
```

Para concurrencia se usa columna `version` y actualización `WHERE id = ? AND version = ?`; conflictos devuelven `409`. Reservas, folios, secuencias de revisión y cierres usan transacción y `SELECT ... FOR UPDATE` donde el índice permita un rango determinista.

## 6. Configuración operativa mínima

- Conexiones `mysql` (escritura nueva) y `legacy_reposs`, `legacy_compartida`, `legacy_laboratorios`, `legacy_inventarios` en sólo lectura.
- Usuario MySQL de runtime sin `CREATE`, `ALTER`, `DROP`, `TRIGGER` ni acceso a schemas legacy de escritura.
- `queue=database` inicialmente; al menos un worker supervisado y cron cada minuto para `schedule:run`.
- Cache/sesión database en un nodo; si hay alta disponibilidad, Redis requiere ADR y operación propia.
- Configuración por variables de entorno/secret store; `config:cache`, `route:cache` y artefactos frontend se generan en build.
- Fechas persistidas en UTC; conversiones de zona sólo en Presentation.

## 7. Estrategia de pruebas

| Nivel | Alcance | Dependencias |
|---|---|---|
| Unit | Aggregates, value objects, policies de dominio | Sin framework/DB. |
| Integration | Repositories, mappers, locks, constraints, outbox | MySQL 8 real en CI, no SQLite. |
| Feature | HTTP, auth, policies, Problem Details | Laravel + MySQL; storage/Entra simulados por ports. |
| Contract | OpenAPI request/response y cliente Angular | Spec versionada. |
| Characterization | Paridad con legacy | Fixtures anonimizados/golden masters. |
| E2E | Flujos críticos por rol | Entorno efímero completo. |

Cobertura es señal, no objetivo aislado: mínimo 80% líneas en Application/Domain y 100% de transiciones/invariantes críticas con tests explícitos.
