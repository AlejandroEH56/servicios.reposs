# Arquitectura ejecutable Angular

## 1. Baseline

Angular 22.x standalone es el baseline recomendado a la fecha de inicio y satisface Angular 20+. Si operación fija otra versión, debe estar dentro de soporte oficial durante todo el despliegue. TypeScript, Node y RxJS se fijan según la matriz oficial de compatibilidad, no con rangos abiertos.

Decisión de estado:

- Signals: estado de vista/feature, selección, filtros y valores derivados.
- RxJS: HTTP, eventos, debounce, cancelación, combinación de streams y polling.
- NgRx: no se instala en Sprint 0. Requiere ADR por feature si hay múltiples escritores, efectos complejos, replay/devtools como requisito o coordinación que una facade con Signals no pueda mantener simple.
- “RxJS Store” casero basado en `BehaviorSubject` no se adopta como tercera arquitectura.

## 2. Estructura exacta

```text
frontend/src/app/
├── app.component.ts
├── app.config.ts
├── app.routes.ts
├── core/
│   ├── auth/{auth.facade.ts,auth.store.ts,auth.models.ts}
│   ├── guards/{auth.guard.ts,anonymous.guard.ts,permission.guard.ts,scope.guard.ts}
│   ├── http/{api-error.interceptor.ts,correlation-id.interceptor.ts,credentials.interceptor.ts}
│   ├── errors/{problem-details.ts,global-error-handler.ts}
│   ├── layout/{app-shell,nav,access-denied,not-found}/
│   └── config/runtime-config.ts
├── shared/
│   ├── api/generated/
│   ├── ui/{data-table,paginator,status-chip,confirm-dialog,file-upload}/
│   ├── forms/{server-errors.ts,validators.ts}
│   ├── pipes/
│   └── testing/
└── features/
    ├── iam/
    │   ├── pages/{callback,role-list,identity-role-editor}/
    │   ├── data-access/{iam.facade.ts,iam.store.ts}
    │   └── iam.routes.ts
    ├── organization/
    │   ├── pages/{employee-list,employee-detail,position-editor,organization-tree}/
    │   ├── data-access/{organization.facade.ts,organization.store.ts}
    │   └── organization.routes.ts
    ├── residencies/
    │   ├── pages/{dashboard,profile,company-search,company-editor,project-editor,evidence-list,evidence-upload,review-queue,evidence-review,report-plan,release-detail,document-list}/
    │   ├── data-access/{residencies.facade.ts,residencies.store.ts,upload.service.ts}
    │   └── residencies.routes.ts
    ├── publications/
    │   ├── pages/{public-notice-list,public-notice-detail,notice-admin-list,notice-editor}/
    │   ├── data-access/{publications.facade.ts,publications.store.ts}
    │   └── publications.routes.ts
    ├── academic-planning/
    │   ├── pages/{semester-list,semester-editor,laboratory-list,laboratory-editor,schedule-editor,holiday-list,curriculum-catalogs,availability-view}/
    │   ├── data-access/{planning.facade.ts,planning.store.ts}
    │   └── academic-planning.routes.ts
    ├── laboratory-requests/
    │   ├── pages/{request-list,request-wizard,request-detail,review-queue,availability-calendar}/
    │   ├── data-access/{laboratory-requests.facade.ts,laboratory-requests.store.ts}
    │   └── laboratory-requests.routes.ts
    └── inventories/
        ├── pages/{inventory-selector,dashboard,item-list,item-editor,item-detail,location-tree,incident-list,incident-editor,alert-list,monthly-close,export-list}/
        ├── data-access/{inventories.facade.ts,inventories.store.ts}
        └── inventories.routes.ts
```

`shared/api/generated` se regenera desde OpenAPI y no se edita manualmente. Las páginas no llaman directamente al cliente generado: usan la facade de su feature.

## 3. Routing

```text
/                              → redirect según sesión
/access-denied                 → pública
/not-found                     → pública
/notices                       → publicaciones públicas
/notices/:noticeId             → detalle público
/iam/roles                     → permission iam.roles.read
/organization/employees        → permission organization.employees.read
/organization/employees/:id    → auth + policy server-side
/residencies                   → scope resident:self o residencias.read
/residencies/project/:id       → auth; API decide ownership
/residencies/review            → permission residencias.evidencias.revisar
/academic-planning             → auth
/academic-planning/admin       → permission planning.manage
/laboratory-requests            → auth
/laboratory-requests/new        → permission laboratorios.solicitudes.crear
/laboratory-requests/review     → permission laboratorios.solicitudes.resolver
/inventories/:inventoryId      → scope laboratory
```

Cada feature se carga con `loadChildren`/`loadComponent`. `canMatch` evita descargar features no autorizadas y `canActivate` protege navegación; ninguna de ellas sustituye Policies del backend. `canDeactivate` sólo evita pérdida accidental de formularios.

## 4. Stores y facades

Patrón por feature:

```typescript
type LoadState = 'idle' | 'loading' | 'loaded' | 'error';

interface FeatureState<T> {
  readonly entities: ReadonlyMap<string, T>;
  readonly selectedId: string | null;
  readonly loadState: LoadState;
  readonly problem: ProblemDetails | null;
}
```

- estado privado mediante `signal`;
- selectors públicos con `computed`;
- mutación sólo por métodos de facade/store;
- I/O retorna Observable desde cliente generado y se convierte conscientemente con `toSignal` o se consume con lifecycle seguro;
- no duplicar en store formularios efímeros que ya pertenecen a reactive forms;
- invalidar/refrescar por command exitoso; no hacer optimistic update en validaciones, inventarios, autorizaciones o cierres salvo diseño explícito de rollback.

## 5. Formularios y errores

- Reactive Forms tipados y componentes standalone.
- Validación de cliente replica sólo reglas de experiencia; el backend sigue siendo autoridad.
- `422 errors` se mapea a controles; conflictos `409` muestran recarga/comparación, nunca reenvío automático ciego.
- `401` limpia store de sesión y navega a login preservando únicamente una ruta local allowlisted.
- `403` no reintenta; muestra acceso denegado.
- `429/503` respeta `Retry-After`; GET idempotente puede reintentar con jitter acotado.

## 6. HTTP y seguridad frontend

- `credentialsInterceptor`: `withCredentials` sólo para el origin API configurado.
- `correlationIdInterceptor`: UUID por interacción si no existe; nunca acepta CR/LF.
- `apiErrorInterceptor`: normaliza Problem Details, sin ocultar status.
- No bearer tokens en `localStorage`, `sessionStorage`, URL, NgRx/Signals ni logs.
- No usar `bypassSecurityTrust*` salvo wrapper revisado; sanitización Angular, CSP y Trusted Types en modo enforcement.
- Evitar HTML dinámico; si un aviso admite formato, renderizar Markdown con allowlist en backend y frontend.
- Runtime config permite hosts, no secretos.

Los guards del navegador sólo mejoran UX. La documentación oficial de Angular exige autorización server-side adicional: [route guards](https://angular.dev/guide/routing/route-guards).

## 7. Pruebas y calidad

| Nivel | Cobertura |
|---|---|
| Unit | stores, computed selectors, validators, guards e interceptors. |
| Component | estados loading/empty/error, accesibilidad y formularios. |
| Contract | cliente generado compila contra OpenAPI sin patches. |
| E2E | login simulado, rutas por rol, residencias, solicitud, inventario y publicación. |
| Visual | formatos críticos y componentes de alto riesgo; no snapshots masivos frágiles. |

Gates: TypeScript strict, templates strict, ESLint sin warnings, tests, presupuesto de bundle por feature, auditoría de dependencias, accesibilidad WCAG 2.2 AA en flujos críticos y build de producción reproducible.

Fuentes: [Angular releases](https://angular.dev/reference/releases), [compatibilidad](https://angular.dev/reference/versions), [Signals/RxJS](https://angular.dev/ecosystem/rxjs-interop) y [seguridad Angular](https://angular.dev/best-practices/security).
