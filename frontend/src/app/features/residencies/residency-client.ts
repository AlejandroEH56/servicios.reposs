import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { Api } from '../../api/api';
import { listResidencyModality, getResidencyModality, createResidencyModality, updateResidencyModality, listResidencySector, getResidencySector, createResidencySector, updateResidencySector, listResidencyBusinessLine, getResidencyBusinessLine, createResidencyBusinessLine, updateResidencyBusinessLine, listResidencyProgram, getResidencyProgram, createResidencyProgram, updateResidencyProgram, listResidencyPeriod, getResidencyPeriod, createResidencyPeriod, updateResidencyPeriod, listResidencyResident, getResidencyResident, createResidencyResident, updateResidencyResident, listResidencyCompany, getResidencyCompany, createResidencyCompany, updateResidencyCompany, listResidencyProject, getResidencyProject, createResidencyProject, updateResidencyProject, getResidencyDashboard, listResidencyIdentityCandidates, listResidencyAdviserCandidates, listResidencyContact, createResidencyContact, updateResidencyContact, assignResidencyInternalAdviser, assignResidencyExternalAdviser, endResidencyInternalAdviser, endResidencyExternalAdviser } from '../../api/functions';
import { ResidencyCatalogInput, ResidencyProgramInput, ResidencyPeriodInput, ResidencyEnrollmentInput, ResidencyProfileInput, ResidencyCompanyInput, ResidencyProjectInput, ResidencyContactInput, ResidencyInternalAdviserInput, ResidencyExternalAdviserInput, ResidencyRecord, ResidencyRecordResponse, ResidencyPageResponse, ResidencyDashboardResponse, ResidencyCandidateResponse } from '../../api/models';

export type ResidencyKind = 'modalities' | 'sectors' | 'business-lines' | 'programs' | 'periods' | 'residents' | 'companies' | 'projects';
export type ResidencyBody = Record<string, string | number | boolean | null>;

@Injectable()
export class ResidencyClient {
  private readonly api = inject(Api);

  dashboard(): Observable<ResidencyDashboardResponse> { return this.api.invoke(getResidencyDashboard); }

  list(kind: ResidencyKind, query: { q?: string; pageSize?: number; cursor?: string; active?: 0 | 1; residentId?: string } = {}): Observable<ResidencyPageResponse> {
    switch (kind) {
      case 'modalities': return this.api.invoke(listResidencyModality, query);
      case 'sectors': return this.api.invoke(listResidencySector, query);
      case 'business-lines': return this.api.invoke(listResidencyBusinessLine, query);
      case 'programs': return this.api.invoke(listResidencyProgram, query);
      case 'periods': return this.api.invoke(listResidencyPeriod, query);
      case 'residents': return this.api.invoke(listResidencyResident, query);
      case 'companies': return this.api.invoke(listResidencyCompany, query);
      case 'projects': return this.api.invoke(listResidencyProject, query);
    }
  }

  get(kind: ResidencyKind, record: string): Observable<ResidencyRecordResponse> {
    switch (kind) {
      case 'modalities': return this.api.invoke(getResidencyModality, { record });
      case 'sectors': return this.api.invoke(getResidencySector, { record });
      case 'business-lines': return this.api.invoke(getResidencyBusinessLine, { record });
      case 'programs': return this.api.invoke(getResidencyProgram, { record });
      case 'periods': return this.api.invoke(getResidencyPeriod, { record });
      case 'residents': return this.api.invoke(getResidencyResident, { record });
      case 'companies': return this.api.invoke(getResidencyCompany, { record });
      case 'projects': return this.api.invoke(getResidencyProject, { record });
    }
  }

  save(kind: ResidencyKind, body: ResidencyBody, record: ResidencyRecord | null, key: string, token: string): Observable<ResidencyRecordResponse> {
    const csrf = { 'X-XSRF-TOKEN': token };
    if (record) {
      const version = { ...csrf, record: record.id, 'If-Match': '"' + record.version + '"' };
      switch (kind) {
        case 'modalities': return this.api.invoke(updateResidencyModality, { ...version, body: body as unknown as ResidencyCatalogInput });
        case 'sectors': return this.api.invoke(updateResidencySector, { ...version, body: body as unknown as ResidencyCatalogInput });
        case 'business-lines': return this.api.invoke(updateResidencyBusinessLine, { ...version, body: body as unknown as ResidencyCatalogInput });
        case 'programs': return this.api.invoke(updateResidencyProgram, { ...version, body: body as unknown as ResidencyProgramInput });
        case 'periods': return this.api.invoke(updateResidencyPeriod, { ...version, body: body as unknown as ResidencyPeriodInput });
        case 'residents': return this.api.invoke(updateResidencyResident, { ...version, body: body as unknown as ResidencyProfileInput });
        case 'companies': return this.api.invoke(updateResidencyCompany, { ...version, body: body as unknown as ResidencyCompanyInput });
        case 'projects': return this.api.invoke(updateResidencyProject, { ...version, body: body as unknown as ResidencyProjectInput });
      }
    }
    const idempotency = { ...csrf, 'Idempotency-Key': key };
    switch (kind) {
      case 'modalities': return this.api.invoke(createResidencyModality, { ...idempotency, body: body as unknown as ResidencyCatalogInput });
      case 'sectors': return this.api.invoke(createResidencySector, { ...idempotency, body: body as unknown as ResidencyCatalogInput });
      case 'business-lines': return this.api.invoke(createResidencyBusinessLine, { ...idempotency, body: body as unknown as ResidencyCatalogInput });
      case 'programs': return this.api.invoke(createResidencyProgram, { ...idempotency, body: body as unknown as ResidencyProgramInput });
      case 'periods': return this.api.invoke(createResidencyPeriod, { ...idempotency, body: body as unknown as ResidencyPeriodInput });
      case 'residents': return this.api.invoke(createResidencyResident, { ...idempotency, body: body as unknown as ResidencyEnrollmentInput });
      case 'companies': return this.api.invoke(createResidencyCompany, { ...idempotency, body: body as unknown as ResidencyCompanyInput });
      case 'projects': return this.api.invoke(createResidencyProject, { ...idempotency, body: body as unknown as ResidencyProjectInput });
    }
  }

  candidates(type: 'identities' | 'advisers', q: string): Observable<ResidencyCandidateResponse> {
    return type === 'identities' ? this.api.invoke(listResidencyIdentityCandidates, { q }) : this.api.invoke(listResidencyAdviserCandidates, { q });
  }

  contacts(parent: string, query: { q?: string; cursor?: string; active?: 0 | 1 } = {}): Observable<ResidencyPageResponse> {
    return this.api.invoke(listResidencyContact, { parent, pageSize: 100, ...query });
  }

  saveContact(parent: string, body: ResidencyBody, record: ResidencyRecord | null, key: string, token: string): Observable<ResidencyRecordResponse> {
    return record
      ? this.api.invoke(updateResidencyContact, { parent, record: record.id, 'If-Match': '"' + record.version + '"', 'X-XSRF-TOKEN': token, body: body as unknown as ResidencyContactInput })
      : this.api.invoke(createResidencyContact, { parent, 'Idempotency-Key': key, 'X-XSRF-TOKEN': token, body: body as unknown as ResidencyContactInput });
  }

  assign(type: 'internal' | 'external', project: ResidencyRecord, body: ResidencyBody, key: string, token: string): Observable<ResidencyRecordResponse> {
    const params = { parent: project.id, 'If-Match': '"' + project.version + '"', 'Idempotency-Key': key, 'X-XSRF-TOKEN': token };
    return type === 'internal'
      ? this.api.invoke(assignResidencyInternalAdviser, { ...params, body: body as unknown as ResidencyInternalAdviserInput })
      : this.api.invoke(assignResidencyExternalAdviser, { ...params, body: body as unknown as ResidencyExternalAdviserInput });
  }

  end(type: 'internal' | 'external', project: ResidencyRecord, record: string, endsOn: string, token: string): Observable<ResidencyRecordResponse> {
    const params = { parent: project.id, record, 'If-Match': '"' + project.version + '"', 'X-XSRF-TOKEN': token, body: { endsOn } };
    return type === 'internal' ? this.api.invoke(endResidencyInternalAdviser, params) : this.api.invoke(endResidencyExternalAdviser, params);
  }
}
