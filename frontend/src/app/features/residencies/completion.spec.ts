import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { NgForm } from '@angular/forms';
import { provideRouter } from '@angular/router';
import { WritableSignal } from '@angular/core';
import { CompletionWorkspace } from './completion';
import { AuthFacade } from '../../core/auth/auth.facade';
import { activeIdentity } from '../../core/auth/identity.fixture';
import { ResidencyReport, ResidencyDocumentTemplate } from '../../api/models';

const projectId='01ARZ3NDEKTSV4RRFFQ69G5FAV',residentId='01ARZ3NDEKTSV4RRFFQ69G5FAW',evidenceId='01ARZ3NDEKTSV4RRFFQ69G5FAX',stagedId='01ARZ3NDEKTSV4RRFFQ69G5FAY';
const report:ResidencyReport={id:evidenceId,projectId,residentId,type:'PARCIAL',sequence:1,requirementId:stagedId,version:1,startsOn:'2026-02-01',endsOn:'2026-03-31',evidenceId:null,status:'SIN_ENTREGA',submissionVersion:0,reviewNumber:0,grade:null,observations:null,canSubmit:true,canReview:false,dependenciesCurrent:true};
interface Harness {
  residentId:string;projectId:string;grade:number|null;decision:'APROBADO'|'RECHAZADO';observations:string;selectedTemplate:string;reportSequence:number;
  selected:WritableSignal<ResidencyReport|null>;prepared:WritableSignal<string|null>;templates:WritableSignal<ResidencyDocumentTemplate[]>;
  selectReport(row:ResidencyReport):void;chooseFile(event:Event):void;stage():void;submit():void;review(form:NgForm):void;issue(form:NgForm):void;changeProject():void;
}
describe('Avance y emisión de residencias',()=>{
  let http:HttpTestingController;
  beforeEach(()=>{
    TestBed.configureTestingModule({imports:[CompletionWorkspace],providers:[provideRouter([]),provideHttpClient(),provideHttpClientTesting()]});http=TestBed.inject(HttpTestingController);document.cookie='XSRF-TOKEN=completion-unit; path=/';
  });
  afterEach(()=>{document.cookie='XSRF-TOKEN=; Max-Age=0; path=/';try{http.verify();}finally{TestBed.resetTestingModule();}});
  function setup(permissions=['residencies.files.read','residencies.reports.submit'],global=false,enrolled=true):ComponentFixture<CompletionWorkspace>{
    TestBed.inject(AuthFacade).refresh().subscribe();http.expectOne('/api/v1/me').flush({data:{...activeIdentity(),permissions,scopes:permissions.map(p=>p+(global?':global':':self'))}});
    const fixture=TestBed.createComponent(CompletionWorkspace);fixture.detectChanges();
    http.expectOne('/api/v1/residencies/me').flush({data:{resident:enrolled?{id:residentId,version:1,kind:'residents',status:'ACTIVO'}:null,projects:{items:[],nextCursor:null}}});
    for(const request of http.match(r=>r.method==='GET'))request.flush({data:{items:[],nextCursor:null}});
    fixture.detectChanges();return fixture;
  }
  const harness=(fixture:ComponentFixture<CompletionWorkspace>)=>fixture.componentInstance as unknown as Harness;
  function drain():void {for(const request of http.match(r=>r.method==='GET'&&r.url.startsWith('/api/v1/residencies/')))request.flush({data:request.request.url.endsWith('report-plan')?{...report,items:[report],initialized:true,partialReports:2,finalReports:1,plannedWindows:[]}:request.request.url.endsWith('/release')?{status:'PENDIENTE',eligibility:{requirements:[],reports:[]}}:{items:[],nextCursor:null}});}
  it('explica la inscripción pendiente sin ofrecer administración de plantillas',()=>{
    const fixture=setup([],false,false);expect(fixture.nativeElement.textContent).toContain('Solicita la inscripción a administración');expect(fixture.nativeElement.textContent).not.toContain('Administración de plantillas');fixture.destroy();
  });
  it('captura el destino antes de CSRF y descarta una carga al cambiar el proyecto',()=>{
    const fixture=setup();const component=harness(fixture);component.projectId=projectId;component.selectReport(report);
    component.chooseFile({target:{files:[new File(['%PDF-1.4 test'],'report.pdf',{type:'application/pdf'})]}} as unknown as Event);component.stage();
    component.projectId=stagedId;component.changeProject();drain();http.expectOne('/sanctum/csrf-cookie').flush(null);
    const upload=http.expectOne('/api/v1/residencies/files/staged');expect((upload.request.body as FormData).get('projectId')).toBe(projectId);expect(upload.request.headers.get('X-XSRF-TOKEN')).toBe('completion-unit');
    upload.flush({data:{id:stagedId}});expect(component.prepared()).toBeNull();fixture.destroy();
  });
  it('mantiene la clave de envío al reintentar y envía la versión previa',()=>{
    const fixture=setup();const component=harness(fixture);component.projectId=projectId;component.selected.set(report);component.prepared.set(stagedId);
    component.submit();http.expectOne('/sanctum/csrf-cookie').flush(null);const first=http.expectOne(r=>r.method==='POST');const key=first.request.headers.get('Idempotency-Key');
    expect(first.request.body).toEqual({type:'PARCIAL',sequence:1,stagedFileId:stagedId,expectedSubmissionVersion:0});first.error(new ProgressEvent('network'));
    component.submit();http.expectOne('/sanctum/csrf-cookie').flush(null);const retry=http.expectOne(r=>r.method==='POST');expect(retry.request.headers.get('Idempotency-Key')).toBe(key);retry.flush({data:{...report,evidenceId,status:'ENVIADO',canSubmit:false}});drain();fixture.destroy();
  });
  it('exige calificación al aprobar y motivo al rechazar sin enviar datos parciales',()=>{
    const fixture=setup(['residencies.files.read','residencies.reports.review'],true);const component=harness(fixture);component.projectId=projectId;component.selected.set({...report,evidenceId,canReview:true});
    component.review({invalid:false} as NgForm);http.expectNone('/sanctum/csrf-cookie');component.decision='RECHAZADO';component.review({invalid:false} as NgForm);http.expectNone('/sanctum/csrf-cookie');
    component.observations='Corrección de ensayo';component.review({invalid:false} as NgForm);http.expectOne('/sanctum/csrf-cookie').flush(null);const request=http.expectOne(r=>r.method==='POST');
    expect(request.request.body).toEqual({evidenceId,decision:'RECHAZADO',observations:'Corrección de ensayo',grade:null,expectedReviewNumber:1});request.error(new ProgressEvent('network'));fixture.destroy();
  });
  it('emite un formato final con secuencia uno y sin HTML o snapshot del cliente',()=>{
    const fixture=setup(['residencies.files.read','residencies.documents.issue'],true);const component=harness(fixture);component.projectId=projectId;
    component.templates.set([{id:stagedId,type:'FORMATO_REPORTE_FINAL',name:'Final',version:2,active:true,configuration:{institution:'Instituto',institutionKey:'Clave',city:'Ciudad',departmentTitle:'Departamento',divisionTitle:'División',departmentSignerId:null,divisionSignerId:null},resources:[]}]);component.selectedTemplate=stagedId;component.reportSequence=3;
    component.issue({invalid:false} as NgForm);component.projectId=evidenceId;http.expectOne('/sanctum/csrf-cookie').flush(null);const request=http.expectOne(r=>r.method==='POST');
    expect(request.request.url).toBe('/api/v1/residencies/projects/'+projectId+'/documents');expect(request.request.body).toEqual({type:'FORMATO_REPORTE_FINAL',templateId:stagedId,expectedTemplateVersion:2,reportSequence:1});request.flush({data:{id:stagedId}});fixture.destroy();
  });
});
