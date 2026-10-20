import { Component, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { Api } from '../../api/api';
import { getPublicNotice, listPublicNotices } from '../../api/functions';
import { Notice } from '../../api/models';

@Component({
  selector: 'app-notices', imports: [FormsModule, RouterLink],
  template: `
    <section aria-labelledby="notices-title">
      <nav><a routerLink="/notices">Avisos públicos</a> · <a routerLink="/login">Acceso institucional</a></nav>
      <h2 id="notices-title">Convocatorias y oportunidades</h2>
      <p>Consulta los avisos institucionales publicados y vigentes.</p>
      @if (message()) { <p role="alert">{{ message() }}</p> }
      @if (selected(); as notice) {
        <article aria-labelledby="notice-title">
          <p>{{ label(notice.type) }}</p><h3 id="notice-title">{{ notice.title }}</h3>
          @for (image of notice.resources; track image.id) { <img class="notice-image" [src]="image.downloadUrl" [alt]="notice.title" [width]="image.width" [height]="image.height" /> }
          <p class="notice-copy">{{ notice.description }}</p>
          @if (notice.requirements) { <h4>Requisitos</h4><p class="notice-copy">{{ notice.requirements }}</p> }
          @if (notice.publicationWindow.endsAt) { <p>Cierre: {{ displayDate(notice.publicationWindow.endsAt) }}</p> }
          @if (notice.url) { <a [href]="notice.url" target="_blank" rel="noopener noreferrer">Consultar convocatoria externa</a> }
        </article>
      } @else if (!detailRoute) {
        <form (ngSubmit)="load()">
          <label>Tipo <select name="type" [(ngModel)]="type"><option value="">Todos</option><option value="RESIDENCIAS">Residencias</option><option value="SERVICIO_SOCIAL">Servicio social</option><option value="EMPLEO">Empleo</option></select></label>
          <label>Buscar <input name="search" [(ngModel)]="search" maxlength="100" /></label>
          <button [disabled]="busy()">Buscar avisos</button>
        </form>
        @for (notice of notices(); track notice.id) {
          <article><p>{{ label(notice.type) }}</p><h3><a [routerLink]="['/notices', notice.id]">{{ notice.title }}</a></h3><p class="notice-copy">{{ notice.description }}</p></article>
        } @empty { @if (!busy()) { <p>No hay avisos vigentes que coincidan con la búsqueda.</p> } }
        @if (cursor()) { <button type="button" (click)="load(true)" [disabled]="busy()">Ver más avisos</button> }
      }
      @if (busy()) { <p role="status">Cargando avisos…</p> }
    </section>
  `,
})
export class Notices {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly destroyRef = inject(DestroyRef);
  readonly notices = signal<Notice[]>([]);
  readonly selected = signal<Notice | null>(null);
  readonly cursor = signal<string | null>(null);
  readonly busy = signal(false);
  readonly message = signal('');
  readonly detailRoute = this.route.snapshot.paramMap.has('id');
  type = this.route.snapshot.queryParamMap.get('type') ?? '';
  search = '';

  constructor() {
    if (this.detailRoute) {
      this.busy.set(true);
      this.api.invoke(getPublicNotice, { notice: this.route.snapshot.paramMap.get('id')! }).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
        next: response => { this.selected.set(response.data); this.busy.set(false); }, error: error => this.fail(error),
      });
    } else { this.load(); }
  }

  load(more = false): void {
    this.busy.set(true); this.message.set('');
    const type = ['RESIDENCIAS', 'SERVICIO_SOCIAL', 'EMPLEO'].includes(this.type) ? this.type as Notice['type'] : undefined;
    this.api.invoke(listPublicNotices, { 'page[size]': 10, 'page[after]': more ? this.cursor() ?? undefined : undefined, 'filter[type]': type, q: this.search }).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: response => { this.notices.set(more ? [...this.notices(), ...response.data] : response.data); this.cursor.set(response.page.nextCursor); this.busy.set(false); },
      error: error => { this.notices.set([]); this.fail(error); },
    });
  }

  label(type: string): string { return ({ RESIDENCIAS: 'Residencias', SERVICIO_SOCIAL: 'Servicio social', EMPLEO: 'Empleo' } as Record<string, string>)[type] ?? type; }
  displayDate(value: string): string { return new Date(value).toLocaleString('es-MX'); }
  private fail(error: HttpErrorResponse): void { this.busy.set(false); this.message.set(error.status === 404 ? 'Este aviso no está disponible o su vigencia terminó.' : 'No fue posible cargar los avisos. Intenta de nuevo.'); }
}
