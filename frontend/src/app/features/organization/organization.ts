import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Observable } from 'rxjs';
import { WorkspaceService } from '../../core/auth/workspace.service';
import { addAcademicDegree, assignManualPosition, createEmployee, createLevel, createUnit, finishManualPosition, getEmployee, listIdentityCandidates, listOrganizationEmployees, listOrganizationLevels, listOrganizationUnits, removeAcademicDegree, searchAdvisers, synchronizeEmployeeProfile, updateEmployee, updateLevel, updateUnit } from '../../api/functions';
import { AcademicLevel, CreateEmployeeRequest, CreateUnitRequest, IdentityCandidate, InternalAdviser, OrganizationEmployee, OrganizationEmployeeResponse, OrganizationUnit } from '../../api/models';

export function organizationTree(units: OrganizationUnit[]): { unit: OrganizationUnit; depth: number }[] {
  const result: { unit: OrganizationUnit; depth: number }[] = [];
  const visited = new Set<string>();
  const visit = (unit: OrganizationUnit, depth: number): void => {
    if (visited.has(unit.id)) { return; }
    visited.add(unit.id); result.push({ unit, depth });
    for (const child of units.filter(child => child.parentId === unit.id)) { visit(child, depth + 1); }
  };
  for (const unit of units.filter(unit => !unit.parentId || !units.some(parent => parent.id === unit.parentId))) { visit(unit, 0); }
  for (const unit of units) { visit(unit, 0); }
  return result;
}

@Component({
  selector: 'app-organization', imports: [FormsModule, RouterLink], providers: [WorkspaceService],
  template: `
    <a routerLink="/dashboard">Volver a mi sesión</a>
    <h2>Organization</h2>
    <p role="status" aria-live="polite">{{ work.message() }}</p>
    @if (work.can('organization.empleados.consultar')) {
      <fieldset [disabled]="work.busy()">
        <legend>Empleados</legend>
        <form (ngSubmit)="loadEmployees()"><label>Buscar por nombre <input name="q" [(ngModel)]="query" maxlength="160"></label><button>Buscar empleados</button></form>
        <ul>@for (employee of employees(); track employee.id) {
          <li><button type="button" (click)="selectEmployee(employee.id)">{{ employee.givenNames }} {{ employee.firstSurname }}</button> · {{ employee.status }}</li>
        } @empty { <li>No hay empleados visibles para tu cuenta.</li> }</ul>
      </fieldset>
    }
    @if (admin()) {
      <fieldset [disabled]="work.busy()">
        <legend>Registrar empleado</legend>
        <p>Selecciona una identidad institucional activa y confirma sus nombres. El correo y los puestos Microsoft se obtienen del perfil verificado.</p>
        <form (ngSubmit)="registerEmployee()">
          <label>Identidad <select name="identity" [(ngModel)]="newEmployee.identityId" required><option value="">Seleccionar</option>
            @for (identity of candidates(); track identity.id) { <option [value]="identity.id">{{ identity.displayName }}</option> }
          </select></label>
          <label>Nombres <input name="given" [(ngModel)]="newEmployee.givenNames" maxlength="160" required></label>
          <label>Primer apellido <input name="first" [(ngModel)]="newEmployee.firstSurname" maxlength="100" required></label>
          <label>Segundo apellido <input name="second" [(ngModel)]="newEmployee.secondSurname" maxlength="100"></label>
          <label>Número de empleado <input name="number" [(ngModel)]="newEmployee.employeeNumber" maxlength="40"></label>
          <label><input type="checkbox" name="adviser" [(ngModel)]="newEmployee.isAdviser">Habilitar como asesor</label>
          <button>Registrar empleado</button>
        </form>
      </fieldset>
    }
    @if (selectedEmployee(); as employee) {
      <fieldset [disabled]="work.busy()">
        <legend>Detalle del empleado</legend>
        <h3>{{ employee.givenNames }} {{ employee.firstSurname }} {{ employee.secondSurname }}</h3>
        <p>{{ employee.email }} · {{ employee.status }} · versión {{ employee.version }}</p>
        @if (admin()) {
          <form (ngSubmit)="saveEmployee()">
            <label>Nombres <input name="given" [(ngModel)]="employeeDraft.givenNames" maxlength="160" required></label>
            <label>Primer apellido <input name="first" [(ngModel)]="employeeDraft.firstSurname" maxlength="100" required></label>
            <label>Segundo apellido <input name="second" [(ngModel)]="employeeDraft.secondSurname" maxlength="100"></label>
            <label>Número <input name="number" [(ngModel)]="employeeDraft.employeeNumber" maxlength="40"></label>
            <label>Estado <select name="status" [(ngModel)]="employeeDraft.status"><option>ACTIVO</option><option>EN_LICENCIA</option><option>INACTIVO</option><option>ARCHIVADO</option></select></label>
            <label><input type="checkbox" name="adviser" [(ngModel)]="employeeDraft.isAdviser">Habilitado como asesor</label>
            <button>Guardar empleado</button>
          </form>
          <button type="button" (click)="syncProfile()">Conciliar perfil Microsoft verificado</button>
        }
        <h3>Puestos e historial</h3>
        <p>Los puestos Microsoft son de consulta y pueden no tener unidad confirmada. Los puestos manuales se administran por separado.</p>
        <ul>@for (position of employee.positions; track position.id) {
          <li>{{ position.name }} · {{ position.source }} · {{ position.unitName ?? 'Unidad sin confirmar' }} · {{ position.active ? 'Vigente' : 'Finalizado' }} · {{ position.startsOn }}
            @if (admin() && position.source === 'MANUAL' && position.active) { <button type="button" (click)="finishPosition(position.id)">Finalizar {{ position.name }}</button> }
          </li>
        } @empty { <li>Sin puestos.</li> }</ul>
        @if (admin()) {
          <form (ngSubmit)="addPosition()">
            <label>Puesto manual <input name="position" [(ngModel)]="positionName" maxlength="180" required></label>
            <label>Unidad <select name="unit" [(ngModel)]="positionUnit" required><option value="">Seleccionar</option>
              @for (unit of units(); track unit.id) { @if (unit.active) { <option [value]="unit.id">{{ unit.name }}</option> } }
            </select></label>
            <label>Inicio <input type="date" name="start" [(ngModel)]="positionStart" [max]="today" required></label>
            <button>Agregar puesto manual</button>
          </form>
        }
        <h3>Grados académicos</h3>
        <ul>@for (degree of employee.degrees; track degree.id) {
          <li>{{ degree.levelName }} · {{ degree.program }} · {{ degree.abbreviation }}
            @if (canDegrees()) { <button type="button" (click)="removeDegree(degree.id)">Retirar {{ degree.program }}</button> }
          </li>
        } @empty { <li>Sin grados.</li> }</ul>
        @if (canDegrees()) {
          <form (ngSubmit)="addDegree()">
            <label>Nivel <select name="level" [(ngModel)]="degreeLevel" required><option value="">Seleccionar</option>
              @for (level of levels(); track level.id) { <option [value]="level.id">{{ level.name }}</option> }
            </select></label>
            <label>Programa <input name="program" [(ngModel)]="degreeProgram" maxlength="255" required></label>
            <label>Abreviatura <input name="abbr" [(ngModel)]="degreeAbbreviation" maxlength="30"></label>
            <label>Obtención opcional <input type="date" name="obtained" [(ngModel)]="degreeDate" [max]="today"></label>
            <button>Agregar grado</button>
          </form>
        }
      </fieldset>
    }
    @if (work.can('organization.unidades.consultar')) {
      <fieldset [disabled]="work.busy()">
        <legend>Organigrama</legend>
        <ul role="list">@for (row of tree(); track row.unit.id) {
          <li [style.margin-left.rem]="row.depth * 1.2">{{ row.unit.name }} ({{ row.unit.code }}) · {{ row.unit.active ? 'Activa' : 'Inactiva' }}
            @if (work.can('organization.unidades.gestionar')) { <button type="button" (click)="editUnit(row.unit)">Editar {{ row.unit.name }}</button> }
          </li>
        } @empty { <li>Sin unidades registradas.</li> }</ul>
        @if (work.can('organization.unidades.gestionar')) {
          <form (ngSubmit)="saveUnit()">
            <h3>{{ unitId ? 'Editar unidad' : 'Crear unidad' }}</h3>
            <label>Código <input name="code" [(ngModel)]="unitDraft.code" maxlength="60" pattern="[A-Z0-9_\\-]+" required></label>
            <label>Nombre <input name="name" [(ngModel)]="unitDraft.name" maxlength="180" required></label>
            <label>Tipo <input name="kind" [(ngModel)]="unitDraft.kind" maxlength="40" required></label>
            <label>Unidad superior <select name="parent" [(ngModel)]="unitDraft.parentId"><option value="">Raíz</option>
              @for (unit of units(); track unit.id) { @if (unit.active && unit.id !== unitId) { <option [value]="unit.id">{{ unit.name }}</option> } }
            </select></label>
            <label><input type="checkbox" name="active" [(ngModel)]="unitDraft.active">Activa</label>
            <button>Guardar unidad</button><button type="button" (click)="clearUnit()">Nueva unidad</button>
          </form>
        }
      </fieldset>
    }
    @if (work.can('organization.niveles.gestionar')) {
      <fieldset [disabled]="work.busy()">
        <legend>Niveles académicos</legend>
        <ul>@for (level of levels(); track level.id) { <li>{{ level.name }} · orden {{ level.rank }} <button type="button" (click)="editLevel(level)">Editar {{ level.name }}</button></li> }</ul>
        <form (ngSubmit)="saveLevel()">
          <h3>{{ levelId ? 'Editar nivel' : 'Crear nivel' }}</h3>
          <label>Código <input name="code" [(ngModel)]="levelDraft.code" maxlength="40" pattern="[A-Z0-9_\\-]+" required></label>
          <label>Nombre <input name="name" [(ngModel)]="levelDraft.name" maxlength="100" required></label>
          <label>Orden <input type="number" name="rank" [(ngModel)]="levelDraft.rank" min="1" max="65535" required></label>
          <button>Guardar nivel</button><button type="button" (click)="clearLevel()">Nuevo nivel</button>
        </form>
      </fieldset>
    }
    @if (work.can('organization.asesores.consultar')) {
      <fieldset [disabled]="work.busy()">
        <legend>Directorio de asesores</legend>
        <form (ngSubmit)="findAdvisers()"><label>Nombre <input name="q" [(ngModel)]="adviserQuery" maxlength="160"></label><button>Buscar asesores</button></form>
        <ul>@for (adviser of advisers(); track adviser.id) {
          <li>{{ adviser.displayName }}<ul>@for (position of adviser.positions; track position.id) { <li>{{ position.name }}</li> }@for (degree of adviser.degrees; track degree.id) { <li>{{ degree.levelName }}: {{ degree.program }}</li> }</ul></li>
        } @empty { <li>Sin resultados.</li> }</ul>
      </fieldset>
    }
  `,
})
export class Organization {
  protected readonly work = inject(WorkspaceService);
  protected readonly admin = computed(() => this.work.can('organization.empleados.gestionar'));
  protected readonly employees = signal<OrganizationEmployee[]>([]);
  protected readonly selectedEmployee = signal<OrganizationEmployee | null>(null);
  protected readonly candidates = signal<IdentityCandidate[]>([]);
  protected readonly units = signal<OrganizationUnit[]>([]);
  protected readonly tree = computed(() => organizationTree(this.units()));
  protected readonly levels = signal<AcademicLevel[]>([]);
  protected readonly advisers = signal<InternalAdviser[]>([]);
  protected readonly canDegrees = computed(() => {
    const employee = this.selectedEmployee(); const identity = this.work.auth.identity();
    return !!employee && ['ACTIVO', 'EN_LICENCIA'].includes(employee.status) && !!identity &&
      (identity.scopes.includes('organization.grados.gestionar:global') ||
       (identity.scopes.includes('organization.grados.gestionar:self') && employee.identityId === identity.id));
  });
  protected readonly today = new Date().toLocaleDateString('en-CA');
  protected query = ''; protected adviserQuery = '';
  protected newEmployee: CreateEmployeeRequest = { identityId: '', givenNames: '', firstSurname: '', isAdviser: false };
  protected employeeDraft = { givenNames: '', firstSurname: '', secondSurname: '', employeeNumber: '', isAdviser: false, status: 'ACTIVO' as OrganizationEmployee['status'] };
  protected positionName = ''; protected positionUnit = ''; protected positionStart = this.today;
  protected degreeLevel = ''; protected degreeProgram = ''; protected degreeAbbreviation = ''; protected degreeDate = '';
  protected unitId = ''; protected unitVersion = 0;
  protected unitDraft: CreateUnitRequest = { code: '', name: '', kind: '', parentId: '', active: true };
  protected levelId = ''; protected levelVersion = 0;
  protected levelDraft = { code: '', name: '', rank: 1 };

  constructor() {
    if (this.work.can('organization.empleados.consultar')) { this.loadEmployees(); }
    if (this.admin()) { this.work.run(this.work.api.invoke(listIdentityCandidates), result => this.candidates.set(result.data)); }
    this.loadReferences();
    if (this.work.can('organization.asesores.consultar')) { this.findAdvisers(); }
  }
  protected loadEmployees(): void {
    this.work.run(this.work.api.invoke(listOrganizationEmployees, { q: this.query }), result => this.employees.set(result.data));
  }
  protected selectEmployee(id: string): void {
    this.selectedEmployee.set(null);
    this.work.run(this.work.api.invoke(getEmployee, { employeeId: id }), result => this.acceptEmployee(result.data));
  }
  private acceptEmployee(employee: OrganizationEmployee): void {
    this.selectedEmployee.set(employee);
    this.employeeDraft = { givenNames: employee.givenNames, firstSurname: employee.firstSurname, secondSurname: employee.secondSurname ?? '',
      employeeNumber: employee.employeeNumber ?? '', isAdviser: employee.isAdviser, status: employee.status };
  }
  private mutateEmployee(request: Observable<OrganizationEmployeeResponse>): void {
    const id = this.selectedEmployee()?.id;
    this.work.run(request, result => { this.acceptEmployee(result.data); this.loadEmployees(); }, () => { if (id) { this.selectEmployee(id); } });
  }
  protected registerEmployee(): void {
    this.mutateEmployee(this.work.csrf(token => this.work.api.invoke(createEmployee, { 'X-XSRF-TOKEN': token, body: { ...this.newEmployee, secondSurname: this.newEmployee.secondSurname || null, employeeNumber: this.newEmployee.employeeNumber || null } })));
  }
  protected saveEmployee(): void {
    const employee = this.selectedEmployee(); if (!employee) { return; }
    this.mutateEmployee(this.work.csrf(token => this.work.api.invoke(updateEmployee, { employeeId: employee.id, 'X-XSRF-TOKEN': token,
      body: { ...this.employeeDraft, secondSurname: this.employeeDraft.secondSurname || null, employeeNumber: this.employeeDraft.employeeNumber || null, expectedVersion: employee.version } })));
  }
  protected syncProfile(): void {
    const employee = this.selectedEmployee(); if (!employee) { return; }
    this.mutateEmployee(this.work.csrf(token => this.work.api.invoke(synchronizeEmployeeProfile, { employeeId: employee.id, 'X-XSRF-TOKEN': token, body: { expectedVersion: employee.version } })));
  }
  protected addPosition(): void {
    const employee = this.selectedEmployee(); if (!employee) { return; }
    this.mutateEmployee(this.work.csrf(token => this.work.api.invoke(assignManualPosition, { employeeId: employee.id, 'X-XSRF-TOKEN': token,
      body: { expectedVersion: employee.version, name: this.positionName, unitId: this.positionUnit, startsOn: this.positionStart } })));
  }
  protected finishPosition(positionId: string): void {
    const employee = this.selectedEmployee(); if (!employee) { return; }
    this.mutateEmployee(this.work.csrf(token => this.work.api.invoke(finishManualPosition, { employeeId: employee.id, positionId, 'X-XSRF-TOKEN': token, body: { expectedVersion: employee.version, endsOn: this.today } })));
  }
  protected addDegree(): void {
    const employee = this.selectedEmployee(); if (!employee) { return; }
    this.mutateEmployee(this.work.csrf(token => this.work.api.invoke(addAcademicDegree, { employeeId: employee.id, 'X-XSRF-TOKEN': token,
      body: { expectedVersion: employee.version, levelId: this.degreeLevel, program: this.degreeProgram, abbreviation: this.degreeAbbreviation || null, obtainedOn: this.degreeDate || null } })));
  }
  protected removeDegree(degreeId: string): void {
    const employee = this.selectedEmployee(); if (!employee) { return; }
    this.mutateEmployee(this.work.csrf(token => this.work.api.invoke(removeAcademicDegree, { employeeId: employee.id, degreeId, 'X-XSRF-TOKEN': token, body: { expectedVersion: employee.version } })));
  }
  private loadReferences(): void {
    if (this.work.can('organization.unidades.consultar')) { this.work.run(this.work.api.invoke(listOrganizationUnits), result => this.units.set(result.data)); }
    if (this.work.can('organization.niveles.consultar')) { this.work.run(this.work.api.invoke(listOrganizationLevels), result => this.levels.set(result.data)); }
  }
  protected editUnit(unit: OrganizationUnit): void { this.unitId = unit.id; this.unitVersion = unit.version; this.unitDraft = { code: unit.code, name: unit.name, kind: unit.kind, active: unit.active, parentId: unit.parentId ?? '' }; }
  protected clearUnit(): void { this.unitId = ''; this.unitDraft = { code: '', name: '', kind: '', active: true, parentId: '' }; }
  protected saveUnit(): void {
    const body = { ...this.unitDraft, parentId: this.unitDraft.parentId || null };
    this.work.run(this.work.csrf(token => this.unitId
      ? this.work.api.invoke(updateUnit, { unitId: this.unitId, 'X-XSRF-TOKEN': token, body: { ...body, expectedVersion: this.unitVersion } })
      : this.work.api.invoke(createUnit, { 'X-XSRF-TOKEN': token, body })), () => { this.clearUnit(); this.loadReferences(); }, () => { this.clearUnit(); this.loadReferences(); });
  }
  protected editLevel(level: AcademicLevel): void { this.levelId = level.id; this.levelVersion = level.version; this.levelDraft = { code: level.code, name: level.name, rank: level.rank }; }
  protected clearLevel(): void { this.levelId = ''; this.levelDraft = { code: '', name: '', rank: 1 }; }
  protected saveLevel(): void {
    this.work.run(this.work.csrf(token => this.levelId
      ? this.work.api.invoke(updateLevel, { levelId: this.levelId, 'X-XSRF-TOKEN': token, body: { ...this.levelDraft, expectedVersion: this.levelVersion } })
      : this.work.api.invoke(createLevel, { 'X-XSRF-TOKEN': token, body: this.levelDraft })), () => { this.clearLevel(); this.loadReferences(); }, () => { this.clearLevel(); this.loadReferences(); });
  }
  protected findAdvisers(): void { this.work.run(this.work.api.invoke(searchAdvisers, { q: this.adviserQuery }), result => this.advisers.set(result.data)); }
}
