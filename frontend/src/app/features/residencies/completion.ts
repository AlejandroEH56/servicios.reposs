import { Component, DOCUMENT, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule, NgForm } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ResidencyCandidate, ResidencyDocumentTemplate, ResidencyIssuedDocument, ResidencyRecord, ResidencyReport, ResidencyReportPlanResponse, ResidencyReleaseDetailResponse, ResidencyReleaseHistoryResponse, EvidenceRecord, ResidencyTemplateVersionInput, ResidencyDocumentIssueInput } from '../../api/models';
import { approveResidencyProject, initializeResidencyReportPlan, getResidencyReportPlan, getResidencyRelease, listResidencyReleaseHistory, submitResidencyReport, reviewResidencyReport, reopenResidencyEvidence, listResidencyEvidenceReopenings, getEvidenceHistory, stageResidencyEvidence, downloadResidencyEvidence$Pdf, resolveResidencyRelease, reopenResidencyRelease, listResidencyDocumentTemplates, listResidencyDocumentSigners, createResidencyTemplateVersion, stageResidencyTemplateResource, listResidencyIssuedDocuments, issueResidencyDocument, getResidencyIssuedDocument, downloadResidencyIssuedDocument, annulResidencyIssuedDocument, retryResidencyIssuedDocument } from '../../api/functions';
import { WorkspaceService } from '../../core/auth/workspace.service';
import { ResidencyClient } from './residency-client';

type TemplateType=ResidencyDocumentTemplate['type'];
@Component({selector:'app-residency-completion',imports:[FormsModule,RouterLink],providers:[WorkspaceService,ResidencyClient],templateUrl:'./completion.html'})
export class CompletionWorkspace implements OnInit {
  protected readonly work=inject(WorkspaceService);
  private readonly directory=inject(ResidencyClient);
  private readonly document=inject(DOCUMENT);
  protected readonly residents=signal<ResidencyRecord[]>([]);
  protected readonly projects=signal<ResidencyRecord[]>([]);
  protected readonly project=signal<ResidencyRecord|null>(null);
  protected readonly plan=signal<ResidencyReportPlanResponse['data']|null>(null);
  protected readonly release=signal<ResidencyReleaseDetailResponse['data']|null>(null);
  protected readonly releaseHistory=signal<ResidencyReleaseHistoryResponse['data']['items']>([]);
  protected readonly releaseNext=signal<string|null>(null);
  protected readonly templates=signal<ResidencyDocumentTemplate[]>([]);
  protected readonly signers=signal<ResidencyCandidate[]>([]);
  protected readonly documents=signal<ResidencyIssuedDocument[]>([]);
  protected readonly documentNext=signal<string|null>(null);
  protected readonly history=signal<EvidenceRecord[]>([]);
  protected readonly historyNext=signal<string|null>(null);
  protected readonly reopenings=signal<{number:number;observations:string;previousReviewNumber:number}[]>([]);
  protected readonly reopeningNext=signal<string|null>(null);
  protected readonly canReopen=signal(false);
  protected readonly selected=signal<ResidencyReport|null>(null);
  protected readonly prepared=signal<string|null>(null);
  protected readonly notice=signal('');
  protected readonly enrollmentMissing=signal(false);
  protected readonly corrections=computed(()=>{
    const labels:Record<string,string>={file:'Archivo',name:'Nombre',type:'Tipo de reporte o documento',sequence:'Número de reporte',stagedFileId:'Archivo comprobado',grade:'Calificación',observations:'Observaciones o motivo',resolution:'Resolución',expectedVersion:'Versión del registro',expectedReviewNumber:'Versión de revisión',expectedSubmissionVersion:'Versión del reporte',templateId:'Plantilla',expectedTemplateVersion:'Versión de plantilla',expectedPreviousVersion:'Versión anterior',departmentSignerId:'Firmante de departamento',divisionSignerId:'Firmante de división',departmentTitle:'Cargo de departamento',divisionTitle:'Cargo de división',resourceIds:'Logotipos',recipientContactId:'Titular de empresa',reportSequence:'Número de reporte',adviserAssignmentId:'Asesor beneficiario',year:'Año',semester:'Semestre',startsOn:'Inicio del intervalo',endsOn:'Fin del intervalo'};
    return [...new Set(this.work.invalidFields().map(field=>labels[field.split('.')[0]]??'Formulario'))];
  });
  protected readonly view=signal<'project'|'templates'>('project');
  protected readonly residentNext=signal<string|null>(null);
  protected readonly projectNext=signal<string|null>(null);
  protected residentId='';protected projectId='';protected residentSearch='';protected signerSearch='';
  protected file:File|null=null;private uploadKey='';private serial=0;
  protected decision:'APROBADO'|'RECHAZADO'|'CAMBIOS_SOLICITADOS'='APROBADO';protected grade:number|null=null;protected observations='';
  protected releaseDecision:'APROBADO'|'RECHAZADO'='APROBADO';protected resolution='';protected releaseReason='';
  protected selectedTemplate='';protected reportSequence=1;protected recipientContactId='';protected adviserAssignmentId='';
  protected year=new Date().getFullYear();protected semester=1;protected startsOn='';protected endsOn='';protected annulmentReason='';
  protected templateDraft={name:'',active:true,departmentSignerId:'',divisionSignerId:'',departmentTitle:'Departamento de Residencias Profesionales y Servicio Social',divisionTitle:'División de Carrera'};
  protected templateType:TemplateType='SOLICITUD_RESIDENCIA';protected readonly editingTemplate=signal<ResidencyDocumentTemplate|null>(null);
  protected readonly resourceIds=signal<string[]>([]);protected resourceFile:File|null=null;private resourceKey='';
  protected readonly typeLabels:Record<TemplateType,string>={SOLICITUD_RESIDENCIA:'Solicitud de residencia',CARTA_PRESENTACION_FORMAL:'Carta de presentación formal',CARTA_PRESENTACION_INFORMAL:'Carta de presentación informal',FORMATO_REPORTE_PARCIAL:'Evaluación de reporte parcial',FORMATO_REPORTE_FINAL:'Evaluación de reporte final',CONSTANCIA_ASESORIA:'Constancia de asesoría'};
  private readonly keys=new Map<string,{signature:string;key:string}>();

  ngOnInit():void {
    this.work.run(this.directory.dashboard(),r=>{
      this.enrollmentMissing.set(!this.global()&&!r.data.resident);
      if(this.global())this.loadResidents();else if(r.data.resident){this.residentId=r.data.resident.id;this.loadProjects();}
      if(this.work.can('residencies.templates.manage')){this.loadTemplates();this.loadSigners();}
    });
  }
  protected global():boolean{return this.work.auth.identity()?.scopes.includes('residencies.files.read:global')??false;}
  private key(slot:string,body:unknown):string {
    const signature=JSON.stringify([this.projectId,body]);const old=this.keys.get(slot);if(old?.signature===signature)return old.key;
    const key=crypto.randomUUID();this.keys.set(slot,{signature,key});return key;
  }
  private context():string{return this.residentId+'|'+this.projectId+'|'+this.serial;}
  protected loadResidents(more=false):void {
    this.work.run(this.directory.list('residents',{q:this.residentSearch||undefined,pageSize:100,cursor:more?this.residentNext()??undefined:undefined}),r=>{this.residents.set(more?[...this.residents(),...r.data.items]:r.data.items);this.residentNext.set(r.data.nextCursor);});
  }
  protected changeResident():void {this.projectId='';this.projects.set([]);this.changeProject();this.loadProjects();}
  protected loadProjects(more=false):void {
    if(!this.residentId)return;const resident=this.residentId;
    this.work.run(this.directory.list('projects',{residentId:resident,pageSize:100,cursor:more?this.projectNext()??undefined:undefined}),r=>{if(this.residentId!==resident)return;this.projects.set(more?[...this.projects(),...r.data.items]:r.data.items);this.projectNext.set(r.data.nextCursor);});
  }
  protected changeProject():void {
    this.serial++;this.project.set(null);this.plan.set(null);this.release.set(null);this.releaseHistory.set([]);this.releaseNext.set(null);this.documents.set([]);this.documentNext.set(null);this.clearReport();this.keys.clear();this.notice.set('');this.refresh();
  }
  protected refresh():void {
    if(!this.projectId)return;const context=this.context();const record=this.projectId;
    this.work.run(this.directory.get('projects',record),r=>{if(context===this.context())this.project.set(r.data);});
    this.work.run(this.work.api.invoke(getResidencyReportPlan,{record}),r=>{if(context!==this.context())return;this.plan.set(r.data);const old=this.selected();if(old){this.selected.set(r.data.items.find(row=>row.id===old.id)??null);this.loadHistory();this.loadReopenings();}});
    this.work.run(this.work.api.invoke(getResidencyRelease,{record}),r=>{if(context===this.context())this.release.set(r.data);});
    this.loadDocuments();this.loadReleaseHistory();
  }
  protected initialize():void {
    if(!this.projectId)return;const context=this.context();const record=this.projectId;
    this.work.run(this.work.csrf(token=>this.work.api.invoke(initializeResidencyReportPlan,{record,'X-XSRF-TOKEN':token,'Idempotency-Key':this.key('initialize',{}),body:{}})),()=>{if(context===this.context()){this.notice.set('Plan de reportes inicializado.');this.refresh();}});
  }
  protected approveProject():void {
    const plan=this.plan();if(!plan)return;const context=this.context();const body={expectedVersion:plan.projectVersion};const record=this.projectId;const key=this.key('approve',body);
    this.work.run(this.work.csrf(token=>this.work.api.invoke(approveResidencyProject,{record,'X-XSRF-TOKEN':token,'Idempotency-Key':key,body})),()=>{if(context===this.context()){this.notice.set('Proyecto aprobado. Sus datos y asesores quedan fijados.');this.refresh();}},()=>this.refresh());
  }
  private clearReport():void {this.selected.set(null);this.canReopen.set(false);this.history.set([]);this.historyNext.set(null);this.reopenings.set([]);this.reopeningNext.set(null);this.file=null;this.prepared.set(null);this.grade=null;this.observations='';}
  protected selectReport(report:ResidencyReport):void {this.clearReport();this.selected.set(report);this.loadHistory();this.loadReopenings();}
  protected chooseFile(event:Event):void {const input=event.target as HTMLInputElement;this.file=input.files?.[0]??null;input.value='';this.prepared.set(null);this.uploadKey=crypto.randomUUID();this.keys.delete('submit');}
  protected stage():void {
    const report=this.selected();const file=this.file;if(!report?.canSubmit||!file)return;
    if(file.size===0||file.size>10485760){this.work.message.set('Selecciona un PDF de hasta 10 MB.');return;}
    const context=this.context();const residentId=this.residentId;const projectId=this.projectId;const key=this.uploadKey;
    this.work.run(this.work.csrf(token=>this.work.api.invoke(stageResidencyEvidence,{'X-XSRF-TOKEN':token,'Idempotency-Key':key,body:{purpose:'RESIDENCY_EVIDENCE',file,residentId,projectId,requirementId:report.requirementId}})),r=>{if(context===this.context()&&this.selected()?.id===report.id&&this.file===file){this.prepared.set(r.data.id);this.notice.set('PDF comprobado. Pulsa Enviar reporte.');}});
  }
  protected submit():void {
    const report=this.selected();const stagedFileId=this.prepared();if(!report?.canSubmit||!stagedFileId)return;
    const context=this.context();const body={type:report.type,sequence:report.sequence,stagedFileId,expectedSubmissionVersion:report.submissionVersion};const record=this.projectId;const key=this.key('submit',body);
    this.work.run(this.work.csrf(token=>this.work.api.invoke(submitResidencyReport,{record,'X-XSRF-TOKEN':token,'Idempotency-Key':key,body})),r=>{if(context!==this.context())return;this.file=null;this.prepared.set(null);this.selected.set(r.data);this.notice.set('Reporte enviado.');this.refresh();},()=>this.refresh());
  }
  protected review(form:NgForm):void {
    const report=this.selected();if(!report?.canReview||form.invalid)return;
    if((this.decision==='APROBADO'&&this.grade===null)||(this.decision!=='APROBADO'&&!this.observations.trim())){this.work.message.set('Indica calificación al aprobar u observaciones al rechazar.');return;}
    const body={evidenceId:report.evidenceId!,decision:this.decision,observations:this.observations||null,grade:this.decision==='APROBADO'?this.grade:null,expectedReviewNumber:report.reviewNumber+1};const context=this.context();const record=this.projectId;const key=this.key('review',body);
    this.work.run(this.work.csrf(token=>this.work.api.invoke(reviewResidencyReport,{record,'X-XSRF-TOKEN':token,'Idempotency-Key':key,body})),()=>{if(context===this.context()){this.notice.set('Revisión registrada.');this.refresh();}},()=>this.refresh());
  }
  protected reopenReport():void {
    const report=this.selected();if(!this.canReopen())return;if(!report?.evidenceId||!this.observations.trim()){this.work.message.set('Indica el motivo de reapertura.');return;}
    const context=this.context();const body={observations:this.observations,expectedReviewNumber:report.reviewNumber};
    this.work.run(this.work.csrf(token=>this.work.api.invoke(reopenResidencyEvidence,{record:report.evidenceId!,'X-XSRF-TOKEN':token,'Idempotency-Key':this.key('reopen',body),body})),()=>{if(context===this.context()){this.notice.set('Revisión reabierta. Los reportes posteriores deberán conciliarse.');this.refresh();}},()=>this.refresh());
  }
  protected loadHistory(more=false):void {
    const report=this.selected();if(!report?.evidenceId)return;const context=this.context();
    this.work.run(this.work.api.invoke(getEvidenceHistory,{record:report.evidenceId,pageSize:25,cursor:more?this.historyNext()??undefined:undefined}),r=>{if(context===this.context()&&this.selected()?.id===report.id){this.history.set(more?[...this.history(),...r.data.items]:r.data.items);this.historyNext.set(r.data.nextCursor);}});
  }
  protected loadReopenings(more=false):void {
    const report=this.selected();if(!report?.evidenceId)return;const context=this.context();
    this.work.run(this.work.api.invoke(listResidencyEvidenceReopenings,{record:report.evidenceId,pageSize:25,cursor:more?this.reopeningNext()??undefined:undefined}),r=>{if(context===this.context()&&this.selected()?.id===report.id&&this.selected()?.evidenceId===report.evidenceId){this.canReopen.set(r.data.canReopen??false);this.reopenings.set(more?[...this.reopenings(),...r.data.items]:r.data.items);this.reopeningNext.set(r.data.nextCursor);}});
  }
  protected decideRelease(reopen=false):void {
    const release=this.release();if(!release||!this.releaseReason.trim()){this.work.message.set('Indica el motivo de la resolución o reapertura.');return;}
    const context=this.context();const base={observations:this.releaseReason,expectedVersion:release.version};const body={...base,decision:this.releaseDecision,resolution:this.resolution||null};const record=this.projectId;const key=this.key(reopen?'release-reopen':'release',reopen?base:body);
    this.work.run(this.work.csrf(token=>reopen?this.work.api.invoke(reopenResidencyRelease,{record,'X-XSRF-TOKEN':token,'Idempotency-Key':key,body:base}):this.work.api.invoke(resolveResidencyRelease,{record,'X-XSRF-TOKEN':token,'Idempotency-Key':key,body})),()=>{if(context===this.context()){this.notice.set(reopen?'Liberación reabierta; sus constancias quedaron anuladas.':'Resolución registrada.');this.refresh();}},()=>this.refresh());
  }
  protected loadReleaseHistory(more=false):void {
    if(!this.projectId)return;const context=this.context();
    this.work.run(this.work.api.invoke(listResidencyReleaseHistory,{record:this.projectId,pageSize:25,cursor:more?this.releaseNext()??undefined:undefined}),r=>{if(context===this.context()){this.releaseHistory.set(more?[...this.releaseHistory(),...r.data.items]:r.data.items);this.releaseNext.set(r.data.nextCursor);}});
  }
  protected loadDocuments(more=false):void {
    if(!this.projectId)return;const context=this.context();
    this.work.run(this.work.api.invoke(listResidencyIssuedDocuments,{record:this.projectId,pageSize:25,cursor:more?this.documentNext()??undefined:undefined}),r=>{if(context===this.context()){this.documents.set(more?[...this.documents(),...r.data.items]:r.data.items);this.documentNext.set(r.data.nextCursor);}});
  }
  protected loadTemplates():void {this.work.run(this.work.api.invoke(listResidencyDocumentTemplates,{pageSize:100}),r=>this.templates.set(r.data.items));}
  protected loadSigners():void {this.work.run(this.work.api.invoke(listResidencyDocumentSigners,{q:this.signerSearch||undefined}),r=>this.signers.set(r.data.items));}
  protected editTemplate(template:ResidencyDocumentTemplate):void {
    this.editingTemplate.set(template);this.templateType=template.type;this.resourceIds.set([]);this.resourceFile=null;
    const config=template.configuration;this.templateDraft={name:template.name,active:template.active,departmentSignerId:config.departmentSignerId??'',divisionSignerId:config.divisionSignerId??'',departmentTitle:config.departmentTitle,divisionTitle:config.divisionTitle};
  }
  protected chooseResource(event:Event):void {const input=event.target as HTMLInputElement;this.resourceFile=input.files?.[0]??null;input.value='';this.resourceKey=crypto.randomUUID();}
  protected stageResource():void {
    const file=this.resourceFile;const template=this.editingTemplate();if(!file||!template||this.resourceIds().length>=6)return;
    if(file.size===0||file.size>524288){this.work.message.set('Selecciona un logotipo PNG o JPEG de hasta 512 KB.');return;}
    const key=this.resourceKey;
    this.work.run(this.work.csrf(token=>this.work.api.invoke(stageResidencyTemplateResource,{'X-XSRF-TOKEN':token,'Idempotency-Key':key,body:{purpose:'TEMPLATE_RESOURCE',file}})),r=>{if(this.editingTemplate()?.id===template.id&&this.resourceFile===file){this.resourceIds.update(ids=>[...new Set([...ids,r.data.id])]);this.resourceFile=null;this.notice.set('Logotipo comprobado.');}});
  }
  protected saveTemplate(form:NgForm):void {
    const template=this.editingTemplate();if(!template||form.invalid)return;
    const body:ResidencyTemplateVersionInput={type:template.type,...this.templateDraft,departmentSignerId:this.templateDraft.departmentSignerId||null,divisionSignerId:this.templateDraft.divisionSignerId||null,expectedPreviousVersion:template.version,...(this.resourceIds().length?{resourceIds:this.resourceIds()}: {})};
    this.work.run(this.work.csrf(token=>this.work.api.invoke(createResidencyTemplateVersion,{'X-XSRF-TOKEN':token,'Idempotency-Key':this.key('template',body),body})),r=>{if(this.editingTemplate()?.id===template.id)this.editTemplate(r.data);this.loadTemplates();this.notice.set('Nueva versión de plantilla guardada. Las emisiones previas conservan su versión.');},()=>this.loadTemplates());
  }
  protected issue(form:NgForm):void {
    if(form.invalid||!this.projectId)return;const template=this.templates().find(row=>row.id===this.selectedTemplate&&row.active);if(!template)return;
    const body:ResidencyDocumentIssueInput={type:template.type,templateId:template.id,expectedTemplateVersion:template.version};
    if(template.type==='SOLICITUD_RESIDENCIA')body.recipientContactId=this.recipientContactId||null;
    if(template.type.startsWith('FORMATO_REPORTE_'))body.reportSequence=template.type==='FORMATO_REPORTE_FINAL'?1:this.reportSequence;
    if(template.type==='CONSTANCIA_ASESORIA')Object.assign(body,{adviserAssignmentId:this.adviserAssignmentId,year:this.year,semester:this.semester,startsOn:this.startsOn,endsOn:this.endsOn});
    const context=this.context();const record=this.projectId;const key=this.key('issue',body);this.work.run(this.work.csrf(token=>this.work.api.invoke(issueResidencyDocument,{record,'X-XSRF-TOKEN':token,'Idempotency-Key':key,body})),()=>{if(context===this.context()){this.notice.set('Emisión solicitada. Actualiza su estado hasta que aparezca LISTO.');this.loadDocuments();this.selectedTemplate='';}},()=>{this.refresh();this.loadTemplates();});
  }
  protected issueType():TemplateType|undefined{return this.templates().find(row=>row.id===this.selectedTemplate)?.type;}
  protected documentAction(row:ResidencyIssuedDocument,action:'refresh'|'annul'|'retry'):void {
    const context=this.context();const body={expectedVersion:row.version,observations:this.annulmentReason};
    if(action==='annul'&&!this.annulmentReason.trim()){this.work.message.set('Indica el motivo de anulación.');return;}
    const request=action==='refresh'?this.work.api.invoke(getResidencyIssuedDocument,{record:row.id}):this.work.csrf(token=>action==='annul'?this.work.api.invoke(annulResidencyIssuedDocument,{record:row.id,'X-XSRF-TOKEN':token,'Idempotency-Key':this.key('annul-'+row.id,body),body}):this.work.api.invoke(retryResidencyIssuedDocument,{record:row.id,'X-XSRF-TOKEN':token,'Idempotency-Key':this.key('retry-'+row.id,row.version),body:{expectedVersion:row.version}}));
    this.work.run(request,r=>{if(context===this.context())this.documents.update(rows=>rows.map(old=>old.id===row.id?r.data:old));},()=>this.loadDocuments());
  }
  protected downloadDocument(row:ResidencyIssuedDocument):void {this.work.run(this.work.api.invoke(downloadResidencyIssuedDocument,{record:row.id}),blob=>this.saveBlob(blob,'document-'+row.id+'.pdf'));}
  protected downloadEvidence(row:EvidenceRecord):void {this.work.run(this.work.api.invoke(downloadResidencyEvidence$Pdf,{record:row.id}),blob=>this.saveBlob(blob,'report-'+row.id+'.pdf'));}
  private saveBlob(blob:Blob,name:string):void {const href=URL.createObjectURL(blob);const link=this.document.createElement('a');link.href=href;link.download=name;link.click();setTimeout(()=>URL.revokeObjectURL(href),1000);}
}
