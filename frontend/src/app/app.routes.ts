import { Routes } from '@angular/router';
import { anonymousGuard, authGuard, authMatchGuard } from './core/guards/auth.guard';
import { permissionGuard } from './core/guards/permission.guard';

export const routes: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'session' },
  { path: 'residencies/evidence', canActivate: [authGuard], loadComponent: () => import('./features/residencies/evidence').then(m => m.EvidenceWorkspace) },
  { path: 'residencies', canActivate: [authGuard], loadComponent: () => import('./features/residencies/residencies').then(m => m.Residencies) },
  { path: 'academic-planning/calendar', canActivate: [authGuard], loadComponent: () => import('./features/academic-planning/calendar').then(m => m.Calendar) },
  { path: 'academic-planning', canActivate: [authGuard], loadComponent: () => import('./features/academic-planning/planning').then(m => m.Planning) },
  { path: 'notices', loadComponent: () => import('./features/publications/notices').then(m => m.Notices) },
  { path: 'notices/:id', loadComponent: () => import('./features/publications/notices').then(m => m.Notices) },
  { path: 'publications', canActivate: [permissionGuard], data: { permissions: ['publicaciones.administrar'] }, loadComponent: () => import('./features/publications/notice-admin').then(m => m.NoticeAdmin) },
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
