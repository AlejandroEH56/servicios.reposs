import { Routes } from '@angular/router';
import { anonymousGuard, authGuard, authMatchGuard } from './core/guards/auth.guard';

export const routes: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'session' },
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
