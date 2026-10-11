import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { forkJoin } from 'rxjs';
import { WorkspaceService } from '../../core/auth/workspace.service';
import { createRole, getRoleAssignments, grantRole, listIamIdentities, listPermissions, listRoles, replaceRolePermissions, revokeRole } from '../../api/functions';
import { AuthorizationIdentity, Permission, Role, RoleAssignments } from '../../api/models';

@Component({
  selector: 'app-iam', imports: [FormsModule, RouterLink], providers: [WorkspaceService],
  template: `
    <a routerLink="/dashboard">Volver a mi sesión</a>
    <h2>Administración IAM</h2>
    <p>Las asignaciones son explícitas. Los cambios de permisos invalidan las sesiones anteriores.</p>
    <p role="status" aria-live="polite">{{ work.message() }}</p>
    <fieldset [disabled]="work.busy()">
      <legend>Roles y permisos</legend>
      <label>Rol <select [ngModel]="selectedRole()?.id ?? ''" (ngModelChange)="selectRole($event)">
        <option value="">Seleccionar</option>
        @for (role of roles(); track role.id) { <option [value]="role.id">{{ role.code }} — {{ role.name }}</option> }
      </select></label>
      @if (selectedRole(); as role) {
        <p>Versión {{ role.version }}</p>
        @if (work.can('iam.roles.gestionar')) {
          <form (ngSubmit)="savePermissions()">
            @for (permission of permissions(); track permission.id) {
              <label>{{ permission.code }}
                <select [name]="permission.id" [(ngModel)]="scopes[permission.id]">
                  <option value="">Sin permiso</option>
                  @for (scope of permission.allowedScopes; track scope) { <option [value]="scope">{{ scope }}</option> }
                </select>
              </label>
            }
            <button>Guardar permisos</button>
          </form>
        } @else {
          @for (permission of role.permissions; track permission.permissionId) { <p>{{ permission.code }}: {{ permission.scope }}</p> }
        }
      }
      @if (work.can('iam.roles.gestionar')) {
        <form (ngSubmit)="create()">
          <h3>Crear rol sin privilegios iniciales</h3>
          <label>Código <input name="code" [(ngModel)]="newCode" pattern="[A-Z][A-Z0-9_]*" maxlength="100" required></label>
          <label>Nombre <input name="name" [(ngModel)]="newName" maxlength="160" required></label>
          <button>Crear rol</button>
        </form>
      }
    </fieldset>
    <fieldset [disabled]="work.busy()">
      <legend>Asignaciones e historial</legend>
      <form (ngSubmit)="loadIdentities()">
        <label>Buscar identidad <input name="query" [(ngModel)]="query" maxlength="160"></label>
        <button>Buscar</button>
      </form>
      <label>Identidad <select [ngModel]="selectedIdentity" (ngModelChange)="selectIdentity($event)">
        <option value="">Seleccionar</option>
        @for (identity of identities(); track identity.id) { <option [value]="identity.id">{{ identity.displayName }} ({{ identity.status }})</option> }
      </select></label>
      @if (assignments(); as assignment) {
        <p>Versión de autorización {{ assignment.authorizationVersion }}</p>
        @for (role of assignment.roles; track role.id) {
          <p>{{ role.code }} — {{ role.expiresAt ?? 'Sin vencimiento' }}
            @if (work.can('iam.roles.gestionar')) { <button type="button" (click)="change(role.id, false)">Revocar {{ role.code }}</button> }
          </p>
        } @empty { <p>Sin roles asignados.</p> }
        @if (work.can('iam.roles.gestionar')) {
          <form (ngSubmit)="change(grantedRole, true)">
            <label>Rol por otorgar <select name="role" [(ngModel)]="grantedRole" required>
              <option value="">Seleccionar</option>
              @for (role of roles(); track role.id) { <option [value]="role.id">{{ role.code }}</option> }
            </select></label>
            <label>Vencimiento opcional <input type="datetime-local" name="expiry" [(ngModel)]="expiry"></label>
            <button>Otorgar rol</button>
          </form>
        }
        <h3>Historial de asignaciones</h3>
        @for (event of assignment.history; track event.id) {
          <p>{{ event.occurredAt }} · {{ event.action }} · {{ roleName(event.roleId) }} · versión {{ event.authorizationVersion }}</p>
        } @empty { <p>Sin movimientos.</p> }
      }
    </fieldset>
  `,
})
export class Iam {
  protected readonly work = inject(WorkspaceService);
  protected readonly roles = signal<Role[]>([]);
  protected readonly permissions = signal<Permission[]>([]);
  protected readonly identities = signal<AuthorizationIdentity[]>([]);
  protected readonly selectedRole = signal<Role | null>(null);
  protected readonly assignments = signal<RoleAssignments | null>(null);
  protected scopes: Record<string, '' | 'SELF' | 'GLOBAL'> = {};
  protected selectedIdentity = '';
  protected newCode = '';
  protected newName = '';
  protected query = '';
  protected grantedRole = '';
  protected expiry = '';

  constructor() { this.load(); this.loadIdentities(); }

  private load(): void {
    this.work.run(forkJoin({ roles: this.work.api.invoke(listRoles), permissions: this.work.api.invoke(listPermissions) }), result => {
      this.roles.set(result.roles.data); this.permissions.set(result.permissions.data);
      if (this.selectedRole()) { this.selectRole(this.selectedRole()!.id); }
    });
  }
  protected selectRole(id: string): void {
    const role = this.roles().find(role => role.id === id) ?? null;
    this.selectedRole.set(role); this.scopes = {};
    for (const permission of role?.permissions ?? []) { this.scopes[permission.permissionId] = permission.scope; }
  }
  protected loadIdentities(): void {
    this.work.run(this.work.api.invoke(listIamIdentities, { q: this.query }), result => this.identities.set(result.data));
  }
  protected selectIdentity(id: string): void {
    this.selectedIdentity = id; this.assignments.set(null);
    if (id) { this.work.run(this.work.api.invoke(getRoleAssignments, { identityId: id }), result => this.assignments.set(result.data)); }
  }
  protected roleName(id: string): string { return this.roles().find(role => role.id === id)?.code ?? id; }
  protected create(): void {
    this.work.run(this.work.csrf(token => this.work.api.invoke(createRole, { 'X-XSRF-TOKEN': token, body: { code: this.newCode, name: this.newName } })), result => {
      this.newCode = ''; this.newName = ''; this.selectedRole.set(result.data); this.load();
    });
  }
  protected savePermissions(): void {
    const role = this.selectedRole(); if (!role) { return; }
    const permissions = Object.entries(this.scopes).filter((entry): entry is [string, 'SELF' | 'GLOBAL'] => entry[1] === 'SELF' || entry[1] === 'GLOBAL')
      .map(([permissionId, scope]) => ({ permissionId, scope }));
    this.work.run(this.work.csrf(token => this.work.api.invoke(replaceRolePermissions, { 'X-XSRF-TOKEN': token, roleId: role.id, body: { expectedVersion: role.version, permissions } })), () => this.load(), () => this.load());
  }
  protected change(roleId: string, grant: boolean): void {
    const assignment = this.assignments(); if (!assignment || !roleId) { return; }
    const params = { identityId: assignment.identityId, roleId, body: { expectedAuthorizationVersion: assignment.authorizationVersion,
      ...(grant && this.expiry ? { expiresAt: new Date(this.expiry).toISOString() } : {}) } };
    this.work.run(this.work.csrf(token => this.work.api.invoke(grant ? grantRole : revokeRole, { ...params, 'X-XSRF-TOKEN': token })), result => {
      this.assignments.set(result.data); this.expiry = '';
      if (assignment.identityId === this.work.auth.identity()?.id) { this.work.auth.refresh().subscribe(); }
    }, () => this.selectIdentity(this.selectedIdentity));
  }
}
