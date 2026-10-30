import {test,expect} from '@playwright/test';
const identity='01ARZ3NDEKTSV4RRFFQ69G5FAV',residentId='01ARZ3NDEKTSV4RRFFQ69G5FAW',projectId='01ARZ3NDEKTSV4RRFFQ69G5FAX',reportId='01ARZ3NDEKTSV4RRFFQ69G5FAY',preparedId='01ARZ3NDEKTSV4RRFFQ69G5FAZ';
async function scenario(page,role){
 const global=role!=='resident';
 const permissions=['residencies.files.read',...(role==='resident'?['residencies.reports.submit']:role==='reviewer'?['residencies.reports.review','residencies.evidence.reopen']:['residencies.reports.submit','residencies.reports.review','residencies.release.resolve','residencies.release.reopen','residencies.templates.manage','residencies.documents.issue','residencies.documents.revoke'])];
 const resident={id:residentId,version:1,kind:'residents',givenNames:'Persona',firstSurname:'Piloto',controlNumber:'99009901',status:'ACTIVO'};
 const project={id:projectId,version:4,kind:'projects',name:'Proyecto de ensayo',status:'APROBADO',residentId,internalAdvisers:[],externalAdvisers:[]};
 let report={id:reportId,projectId,residentId,type:'PARCIAL',sequence:1,requirementId:preparedId,version:1,startsOn:'2026-02-01',endsOn:'2026-03-31',evidenceId:role==='reviewer'?reportId:null,status:role==='reviewer'?'ENVIADO':'SIN_ENTREGA',submissionVersion:role==='reviewer'?1:0,reviewNumber:0,grade:null,observations:null,canSubmit:role==='resident',canReview:role==='reviewer',dependenciesCurrent:true};
 let release={id:null,projectId,projectStatus:'APROBADO',version:0,status:'PENDIENTE',resolution:null,observations:null,resolvedAt:null,eligibility:{requirementsComplete:true,reportsComplete:true,advisersComplete:true,eligible:true,requirements:[],reports:[]}};
 let templates=[{id:preparedId,type:'CARTA_PRESENTACION_INFORMAL',name:'Carta piloto',version:1,active:false,configuration:{institution:'Instituto',institutionKey:'Clave',city:'Ciudad',departmentTitle:'Departamento',divisionTitle:'División',departmentSignerId:null,divisionSignerId:null},resources:[]}];
 let documents=[];const changes=[];const history=[];
 await page.route('**/api/v1/me',route=>route.fulfill({json:{data:{id:identity,displayName:'Identidad piloto',email:null,status:'ACTIVA',roles:[],permissions,scopes:permissions.map(p=>p+(global?':global':':self')),sessionExpiresAt:new Date(Date.now()+300000).toISOString()}}}));
 await page.route('**/api/v1/residencies/**',async route=>{
  const request=route.request();const path=new URL(request.url()).pathname.replace('/api/v1/residencies/','');
  const respond=(data,status=200)=>route.fulfill({status,json:{data}});const paged=items=>respond({items,nextCursor:null});
  if(request.method()==='GET'){
   if(path==='me')return respond({resident:global?null:resident,projects:{items:[],nextCursor:null}});
   if(path==='residents')return paged([resident]);if(path==='projects')return paged([project]);if(path==='projects/'+projectId)return respond(project);
   if(path.endsWith('/report-plan'))return respond({projectId,projectVersion:4,projectStatus:project.status,partialReports:2,finalReports:1,initialized:true,plannedWindows:[],items:[report]});
   if(path.endsWith('/release'))return respond(release);if(path.endsWith('/release/history'))return paged(history);
   if(path==='document-templates')return paged(templates);if(path==='document-signers')return paged([{id:identity,displayName:'Firmante piloto'}]);
   if(path==='projects/'+projectId+'/documents')return paged(documents);
   if(path.startsWith('issued-documents/'))return respond(documents[0]);
   if(path.endsWith('/reopen-history'))return respond({items:[],nextCursor:null,canReopen:false});
   if(path.endsWith('/history'))return paged([]);
  }
  expect(request.headers()['x-xsrf-token']).toBeTruthy();expect(request.headers()['idempotency-key']).toBeTruthy();
  if(path==='files/staged'){
   expect(request.postData()).toContain('RESIDENCY_EVIDENCE');expect(request.postData()).toContain(projectId);changes.push('stage');
   return respond({id:preparedId,status:'READY'},201);
  }
  const body=request.postDataJSON();
  if(path==='projects/'+projectId+'/reports'){
   expect(body).toEqual({type:'PARCIAL',sequence:1,stagedFileId:preparedId,expectedSubmissionVersion:0});changes.push('submit');report={...report,evidenceId:reportId,status:'ENVIADO',submissionVersion:1,canSubmit:false};return respond(report,201);
  }
  if(path.endsWith('/reports/reviews')){
   expect(body).toEqual({evidenceId:reportId,decision:'APROBADO',observations:null,grade:96.5,expectedReviewNumber:1});changes.push('review');report={...report,status:'APROBADO',canReview:false,reviewNumber:1,grade:96.5};return respond({id:reportId,status:'APROBADO'},201);
  }
  if(path==='document-templates'){
   expect(body.expectedPreviousVersion).toBe(1);expect(body.departmentSignerId).toBe(identity);expect(body.html).toBeUndefined();changes.push('template');templates=[{...templates[0],...body,id:preparedId,version:2,active:true}];return respond(templates[0],201);
  }
  if(path==='projects/'+projectId+'/documents'){
   expect(body).toEqual({type:'CARTA_PRESENTACION_INFORMAL',templateId:preparedId,expectedTemplateVersion:2});changes.push('issue');documents=[{id:reportId,projectId,type:body.type,templateId:preparedId,templateVersion:2,version:1,status:'PENDIENTE',snapshotHash:'a'.repeat(64),fileHash:null,fileSize:null,issuedAt:new Date().toISOString(),folio:null,annulmentReason:null,annulledAt:null}];return respond(documents[0],202);
  }
  if(path.endsWith('/release/resolve')){
   expect(body.expectedVersion).toBe(0);expect(body.observations).toBe('Expediente conciliado');changes.push('release');release={...release,id:reportId,status:'APROBADO',projectStatus:'COMPLETADO',version:1};project.status='COMPLETADO';history.push({id:reportId,number:1,status:'APROBADO',observations:body.observations});return respond(release,201);
  }
  if(path.endsWith('/release/reopen')){
   expect(body).toEqual({observations:'Corrección de liberación',expectedVersion:1});changes.push('reopen');release={...release,status:'PENDIENTE',projectStatus:'APROBADO',version:2};project.status='APROBADO';history.push({id:preparedId,number:2,status:'PENDIENTE',observations:body.observations});return respond(release,201);
  }
  throw new Error('Unexpected completion request '+request.method()+' '+path);
 });
 return changes;
}
async function project(page,global){
 await page.goto('/portal/residencies/completion');
 if(global)await page.getByRole('combobox',{name:'Residente',exact:true}).selectOption(residentId);
 await page.getByRole('combobox',{name:'Proyecto',exact:true}).selectOption(projectId);
 await expect(page.getByRole('heading',{name:'Plan de reportes',exact:true})).toBeVisible();
}
test('residente envía un reporte PDF propio y conserva su contexto',async({page})=>{
 const changes=await scenario(page,'resident');await project(page,false);
 await page.getByRole('button',{name:/PARCIAL 1 ·/}).click();
 await page.getByLabel('PDF del reporte (hasta 10 MB)',{exact:true}).setInputFiles({name:'report.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4\n% test\n%%EOF')});
 await page.getByRole('button',{name:'Comprobar PDF',exact:true}).click();await expect(page.getByRole('button',{name:'Enviar reporte',exact:true})).toBeEnabled();
 await page.getByRole('button',{name:'Enviar reporte',exact:true}).click();await expect(page.getByRole('status')).toContainText('Reporte enviado');expect(changes).toEqual(['stage','submit']);
 await expect(page.getByRole('button',{name:'Resolver liberación',exact:true})).toHaveCount(0);await expect(page.getByRole('button',{name:'Solicitar emisión PDF',exact:true})).toHaveCount(0);
});
test('revisor aprueba un reporte con calificación y sin resolver liberación',async({page})=>{
 const changes=await scenario(page,'reviewer');await project(page,true);await page.getByRole('button',{name:/PARCIAL 1 ·/}).click();
 await page.getByLabel('Calificación',{exact:true}).fill('96.5');await page.getByRole('button',{name:'Registrar revisión',exact:true}).click();
 await expect(page.getByRole('status')).toContainText('Revisión registrada');await expect(page.locator('body')).toContainText('Calificación 96.5');expect(changes).toEqual(['review']);await expect(page.getByRole('button',{name:'Resolver liberación',exact:true})).toHaveCount(0);
});
test('administración versiona firmantes y solicita una emisión asíncrona',async({page})=>{
 const changes=await scenario(page,'admin');await project(page,true);await page.getByRole('combobox',{name:'Área',exact:true}).selectOption('templates');
 await page.getByRole('button',{name:'Carta de presentación informal · v1 · Inactiva',exact:true}).click();
 await page.getByRole('combobox',{name:'Firmante de departamento',exact:true}).selectOption(identity);await page.getByLabel('Versión activa',{exact:true}).check();
 await page.getByRole('button',{name:'Guardar nueva versión de plantilla',exact:true}).click();await expect(page.getByRole('button',{name:'Carta de presentación informal · v2 · Activa',exact:true})).toBeVisible();
 await page.getByRole('combobox',{name:'Área',exact:true}).selectOption('project');await page.getByRole('combobox',{name:'Formato activo',exact:true}).selectOption(preparedId);await page.getByRole('button',{name:'Solicitar emisión PDF',exact:true}).click();
 await expect(page.getByRole('status')).toContainText('Emisión solicitada');await expect(page.getByRole('button',{name:'Actualizar estado',exact:true})).toBeVisible();expect(changes).toEqual(['template','issue']);
});
test('administración resuelve y reabre con motivo e historial',async({page})=>{
 const changes=await scenario(page,'admin');await project(page,true);await page.getByLabel('Motivo',{exact:true}).fill('Expediente conciliado');await page.getByRole('button',{name:'Resolver liberación',exact:true}).click();
 await expect(page.getByRole('heading',{name:'Liberación · APROBADO',exact:true})).toBeVisible();await page.getByLabel('Motivo',{exact:true}).fill('Corrección de liberación');await page.getByRole('button',{name:'Reabrir liberación',exact:true}).click();
 await expect(page.getByRole('heading',{name:'Liberación · PENDIENTE',exact:true})).toBeVisible();await expect(page.locator('body')).toContainText('Corrección de liberación');expect(changes).toEqual(['release','reopen']);
});
