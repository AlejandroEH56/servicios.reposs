import { inject } from '@angular/core';
import { CanActivateFn, CanMatchFn, Router } from '@angular/router';
import { map } from 'rxjs';
import { AuthFacade, SessionState } from '../auth/auth.facade';

function destination(
  state: SessionState,
  router: Router,
): true | ReturnType<Router['createUrlTree']> {
  if (state === 'authenticated') {
    return true;
  }
  return router.createUrlTree([
    state === 'denied' ? '/access-denied' : state === 'error' ? '/session-error' : '/login',
  ]);
}

export const authGuard: CanActivateFn = () => {
  const router = inject(Router);
  return inject(AuthFacade)
    .refresh()
    .pipe(map((state) => destination(state, router)));
};

export const authMatchGuard: CanMatchFn = () => {
  const router = inject(Router);
  return inject(AuthFacade)
    .refresh()
    .pipe(map((state) => destination(state, router)));
};

export const anonymousGuard: CanActivateFn = () => {
  const router = inject(Router);
  return inject(AuthFacade)
    .refresh()
    .pipe(
      map((state) => (state === 'authenticated' ? router.createUrlTree(['/dashboard']) : true)),
    );
};
