import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { map } from 'rxjs';
import { AuthFacade } from '../auth/auth.facade';

export const permissionGuard: CanActivateFn = (route) => {
  const auth = inject(AuthFacade);
  const router = inject(Router);
  const required = route.data['permissions'] as string[];
  return auth.refresh().pipe(map(state => {
    if (state !== 'authenticated') {
      return router.createUrlTree([state === 'error' ? '/session-error' : '/login']);
    }
    return required.some(permission => auth.identity()?.permissions.includes(permission))
      ? true : router.createUrlTree(['/access-denied']);
  }));
};
