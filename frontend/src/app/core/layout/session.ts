import { Component, inject } from '@angular/core';
import { Router } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { AuthFacade } from '../auth/auth.facade';

@Component({
  selector: 'app-session',
  template: '<p role="status" aria-live="polite">Comprobando tu sesión institucional…</p>',
})
export class Session {
  constructor() {
    const router = inject(Router);
    inject(AuthFacade)
      .refresh()
      .pipe(takeUntilDestroyed())
      .subscribe((state) => {
        const destination =
          state === 'authenticated'
            ? '/dashboard'
            : state === 'denied'
              ? '/access-denied'
              : state === 'error'
                ? '/session-error'
                : '/login';
        void router.navigate([destination], { replaceUrl: true });
      });
  }
}
