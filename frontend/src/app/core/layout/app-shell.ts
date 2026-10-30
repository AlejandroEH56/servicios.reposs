import { Component, DestroyRef, HostListener, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Router, RouterLink } from '@angular/router';
import { DOCUMENT } from '@angular/core';
import { AuthFacade } from '../auth/auth.facade';

@Component({
  selector: 'app-shell',
  imports: [RouterLink],
  template: `
    <section aria-labelledby="dashboard-title">
      <h2 id="dashboard-title">Tu sesión institucional</h2>
      @if (auth.identity(); as identity) {
        <nav aria-label="Servicios habilitados">
          <a routerLink="/notices">Avisos públicos</a>
          <a routerLink="/residencies">Residencias profesionales</a>
          <a routerLink="/residencies/evidence">Expediente documental</a>
          <a routerLink="/residencies/completion">Reportes, liberación y documentos</a>
          <a routerLink="/academic-planning">Planificación académica</a>
          <a routerLink="/academic-planning/calendar">Horarios y disponibilidad</a>
          @if (identity.permissions.includes('publicaciones.administrar')) { <a routerLink="/publications">Publicaciones</a> }
          @if (identity.permissions.includes('iam.roles.consultar') || identity.permissions.includes('iam.roles.gestionar')) { <a routerLink="/iam">Administración IAM</a> }
          @if (identity.permissions.some(organizationPermission)) { <a routerLink="/organization">Organization</a> }
        </nav>
        <p>Bienvenido, {{ identity.displayName }}.</p>
        <p>Tu cuenta está activa. Los servicios aparecerán aquí conforme se habiliten.</p>
      } @else {
        <p role="status">Comprobando tu sesión…</p>
      }
      @if (logoutFailed()) {
        <p role="alert">No fue posible cerrar la sesión. Inténtalo de nuevo.</p>
      }
      <button type="button" (click)="logout()" [disabled]="auth.busy()">Cerrar sesión</button>
    </section>
  `,
})
export class AppShell {
  protected readonly organizationPermission = (permission: string): boolean => permission.startsWith('organization.') && permission.endsWith('.consultar');
  protected readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);
  private readonly document = inject(DOCUMENT);
  private readonly destroyRef = inject(DestroyRef);
  protected readonly logoutFailed = signal(false);

  protected logout(): void {
    this.logoutFailed.set(false);
    this.auth
      .logout()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((success) => {
        if (success) {
          void this.router.navigate(['/login'], { replaceUrl: true });
        } else {
          this.logoutFailed.set(true);
        }
      });
  }

  @HostListener('document:visibilitychange')
  protected resume(): void {
    if (this.document.visibilityState === 'visible') {
      this.auth
        .refresh()
        .pipe(takeUntilDestroyed(this.destroyRef))
        .subscribe((state) => {
          if (state !== 'authenticated') {
            void this.router.navigate(
              [
                state === 'denied'
                  ? '/access-denied'
                  : state === 'error'
                    ? '/session-error'
                    : '/login',
              ],
              { replaceUrl: true },
            );
          }
        });
    }
  }
}
