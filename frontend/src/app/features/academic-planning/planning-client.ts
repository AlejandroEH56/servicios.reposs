import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { Api } from '../../api/api';
import { activatePlanningSemester, archivePlanningSemester, closePlanningSemester, createPlanningCatalog, createPlanningHoliday, createPlanningLaboratory, createPlanningSemester, getPlanningCatalog, getPlanningHoliday, getPlanningLaboratory, getPlanningSemester, listPlanningCatalog, listPlanningHoliday, listPlanningLaboratory, listPlanningSemester, updatePlanningCatalog, updatePlanningHoliday, updatePlanningLaboratory, updatePlanningSemester } from '../../api/functions';
import { CareersInput, HolidaysInput, LaboratoriesInput, PlanningPageResponse, PlanningRecord, PlanningRecordResponse, SemestersInput } from '../../api/models';

export type PlanningKind = PlanningRecord['kind'];
export type CatalogKind = Exclude<PlanningKind, 'semesters' | 'laboratories' | 'holidays'>;
export type PlanningBody = Record<string, string | number | boolean | null>;

@Injectable()
export class PlanningClient {
  private readonly api = inject(Api);

  list(kind: PlanningKind, query: { q?: string; pageSize?: number; cursor?: string; active?: 0 | 1 }): Observable<PlanningPageResponse> {
    switch (kind) {
      case 'semesters': return this.api.invoke(listPlanningSemester, query);
      case 'laboratories': return this.api.invoke(listPlanningLaboratory, query);
      case 'holidays': return this.api.invoke(listPlanningHoliday, query);
      default: return this.api.invoke(listPlanningCatalog, { ...query, catalog: kind });
    }
  }

  get(kind: PlanningKind, record: string): Observable<PlanningRecordResponse> {
    switch (kind) {
      case 'semesters': return this.api.invoke(getPlanningSemester, { record });
      case 'laboratories': return this.api.invoke(getPlanningLaboratory, { record });
      case 'holidays': return this.api.invoke(getPlanningHoliday, { record });
      default: return this.api.invoke(getPlanningCatalog, { record, catalog: kind });
    }
  }

  save(kind: PlanningKind, body: PlanningBody, record: PlanningRecord | null, key: string): Observable<PlanningRecordResponse> {
    if (record) {
      const version = { record: record.id, 'If-Match': `"${record.version}"` };
      switch (kind) {
        case 'semesters': return this.api.invoke(updatePlanningSemester, { ...version, body: body as unknown as SemestersInput });
        case 'laboratories': return this.api.invoke(updatePlanningLaboratory, { ...version, body: body as unknown as LaboratoriesInput });
        case 'holidays': return this.api.invoke(updatePlanningHoliday, { ...version, body: body as unknown as HolidaysInput });
        default: return this.api.invoke(updatePlanningCatalog, { ...version, catalog: kind, body: body as unknown as CareersInput });
      }
    }
    const idempotency = { 'Idempotency-Key': key };
    switch (kind) {
      case 'semesters': return this.api.invoke(createPlanningSemester, { ...idempotency, body: body as unknown as SemestersInput });
      case 'laboratories': return this.api.invoke(createPlanningLaboratory, { ...idempotency, body: body as unknown as LaboratoriesInput });
      case 'holidays': return this.api.invoke(createPlanningHoliday, { ...idempotency, body: body as unknown as HolidaysInput });
      default: return this.api.invoke(createPlanningCatalog, { ...idempotency, catalog: kind, body: body as unknown as CareersInput });
    }
  }

  transition(action: 'activate' | 'close' | 'archive', record: PlanningRecord, key: string): Observable<PlanningRecordResponse> {
    const params = { record: record.id, 'If-Match': `"${record.version}"`, 'Idempotency-Key': key, body: {} };
    switch (action) {
      case 'activate': return this.api.invoke(activatePlanningSemester, params);
      case 'close': return this.api.invoke(closePlanningSemester, params);
      case 'archive': return this.api.invoke(archivePlanningSemester, params);
    }
  }
}
