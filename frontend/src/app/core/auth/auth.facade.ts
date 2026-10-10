import { DestroyRef, Injectable, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse, HttpXsrfTokenExtractor } from '@angular/common/http';
import { Router } from '@angular/router';
import { Observable, catchError, finalize, map, of, shareReplay, switchMap, tap } from 'rxjs';
import { Api } from '../../api/api';
import { getMe } from '../../api/fn/iam/get-me';
import { prepareCsrf } from '../../api/fn/iam/prepare-csrf';
import { logoutSession } from '../../api/fn/iam/logout-session';
import { Identity } from '../../api/models/identity';

export type SessionState =
  'unknown' | 'loading' | 'authenticated' | 'anonymous' | 'denied' | 'error';

@Injectable({ providedIn: 'root' })
export class AuthFacade {
  private readonly api = inject(Api);
  private readonly xsrf = inject(HttpXsrfTokenExtractor);
  private readonly router = inject(Router);
  private readonly currentIdentity = signal<Identity | null>(null);
  private readonly currentState = signal<SessionState>('unknown');
  private readonly currentBusy = signal(false);
  private pending?: Observable<SessionState>;
  private expiryTimer?: ReturnType<typeof setTimeout>;
  private epoch = 0;

  readonly identity = this.currentIdentity.asReadonly();
  readonly state = this.currentState.asReadonly();
  readonly busy = this.currentBusy.asReadonly();
  readonly authenticated = computed(() => this.state() === 'authenticated');

  constructor() {
    inject(DestroyRef).onDestroy(() => clearTimeout(this.expiryTimer));
  }

  refresh(): Observable<SessionState> {
    if (this.pending) {
      return this.pending;
    }
    this.currentState.set('loading');
    const epoch = this.epoch;
    this.pending = this.api.invoke(getMe).pipe(
      map(({ data }): SessionState => {
        if (epoch !== this.epoch) {
          return this.state();
        }
        this.clear();
        const remaining = Date.parse(data.sessionExpiresAt) - Date.now();
        if (data.status !== 'ACTIVA') {
          this.currentState.set('denied');
        } else if (!Number.isFinite(remaining) || remaining <= 0) {
          this.currentState.set('anonymous');
        } else {
          this.currentIdentity.set(data);
          this.currentState.set('authenticated');
          this.expiryTimer = setTimeout(() => {
            this.clear();
            void this.router.navigate(['/login'], { replaceUrl: true });
          }, remaining);
        }
        return this.state();
      }),
      catchError((error: unknown) => {
        if (epoch !== this.epoch) {
          return of(this.state());
        }
        this.clear();
        this.currentState.set(
          error instanceof HttpErrorResponse && error.status === 401
            ? 'anonymous'
            : error instanceof HttpErrorResponse && error.status === 403
              ? 'denied'
              : 'error',
        );
        return of(this.state());
      }),
      finalize(() => {
        this.pending = undefined;
      }),
      shareReplay({ bufferSize: 1, refCount: false }),
    );
    return this.pending;
  }

  logout(): Observable<boolean> {
    this.currentBusy.set(true);
    return this.api.invoke(prepareCsrf).pipe(
      switchMap(() =>
        this.api.invoke(logoutSession, { 'X-XSRF-TOKEN': this.xsrf.getToken() ?? '' }),
      ),
      tap(() => {
        this.epoch++;
        this.clear();
      }),
      map(() => true),
      catchError((error: unknown) => {
        if (error instanceof HttpErrorResponse && error.status === 401) {
          this.epoch++;
          this.clear();
          return of(true);
        }
        return of(false);
      }),
      finalize(() => this.currentBusy.set(false)),
    );
  }

  private clear(): void {
    clearTimeout(this.expiryTimer);
    this.currentIdentity.set(null);
    this.currentState.set('anonymous');
  }
}
