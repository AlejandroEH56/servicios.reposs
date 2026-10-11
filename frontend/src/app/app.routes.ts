import { Routes } from '@angular/router';
import { anonymousGuard, authGuard, authMatchGuard } from './core/guards/auth.guard';
import { permissionGuard } from './core/guards/permission.guard';

export const routes: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'session' },
  { path: 'iam', canActivate: [permissionGuard], data: { permissions: ['iam.roles.consultar', 'iam.roles.gestionar'] }, loadComponent: () => import('./features/iam/iam').then(m => m.Iam) },
  { path: 'organization', canActivate: [permissionGuard], data: { permissions: ['organization.empleados.consultar', 'organization.asesores.consultar', 'organization.unidades.consultar', 'organization.niveles.consultar'] }, loadComponent: () => import('./features/organization/organization').then(m => m.Organization) },
  {
    path: 'login',
    canActivate: [anonymousGuard],
    loadComponent: () => import('./core/layout/login').then((m) => m.Login),
  },
  { path: 'session', loadComponent: () => import('./core/layout/session').then((m) => m.Session) },
  {
    path: 'dashboard',
    canMatch: [authMatchGuard],
    canActivate: [authGuard],
    loadComponent: () => import('./core/layout/app-shell').then((m) => m.AppShell),
  },
  {
    path: 'access-denied',
    loadComponent: () => import('./core/layout/access-denied').then((m) => m.AccessDenied),
  },
  {
    path: 'session-error',
    loadComponent: () => import('./core/layout/session-error').then((m) => m.SessionError),
  },
  { path: '**', loadComponent: () => import('./core/layout/not-found').then((m) => m.NotFound) },
];
