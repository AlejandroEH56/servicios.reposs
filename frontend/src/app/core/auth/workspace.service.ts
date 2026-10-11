import { HttpErrorResponse, HttpXsrfTokenExtractor } from '@angular/common/http';
import { DestroyRef, Injectable, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Router } from '@angular/router';
import { Observable, finalize, switchMap, throwError } from 'rxjs';
import { Api } from '../../api/api';
import { prepareCsrf } from '../../api/functions';
import { AuthFacade } from './auth.facade';

@Injectable({ providedIn: 'root' })
export class WorkspaceService {
  readonly api = inject(Api);
  readonly auth = inject(AuthFacade);
  readonly busy = signal(false);
  readonly message = signal('');
  private readonly xsrf = inject(HttpXsrfTokenExtractor);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);
  private pending = 0;

  can(permission: string): boolean {
    return this.auth.identity()?.permissions.includes(permission) ?? false;
  }

  csrf<R>(request: (token: string) => Observable<R>): Observable<R> {
    return this.api.invoke(prepareCsrf).pipe(switchMap(() => {
      const token = this.xsrf.getToken();
      return token ? request(token) : throwError(() => new Error('CSRF unavailable'));
    }));
  }

  run<R>(request: Observable<R>, accept: (result: R) => void, conflict?: () => void): void {
    this.pending++;
    this.busy.set(true);
    this.message.set('');
    request.pipe(takeUntilDestroyed(this.destroyRef), finalize(() => this.busy.set(--this.pending > 0)))
      .subscribe({ next: accept, error: (error: unknown) => {
        const status = error instanceof HttpErrorResponse ? error.status : 0;
        const message = status === 409
          ? 'El registro cambió o existe un conflicto. Revisa los datos actualizados antes de guardar.'
          : status === 422 ? 'Revisa los campos y el alcance permitido.'
          : status === 419 ? 'La sesión de edición venció. Vuelve a intentarlo.'
          : 'No fue posible completar la operación.';
        if (status === 401) {
          this.auth.refresh().subscribe(() => void this.router.navigate(['/login']));
        } else if (status === 403) {
          void this.router.navigate(['/access-denied']);
        } else if (status === 409) {
          conflict?.();
        }
        this.message.set(message);
      } });
  }
}
