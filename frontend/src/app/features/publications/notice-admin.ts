import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { createNotice, getAdministrativeNotice, listAdministrativeNotices, publishNotice, stageNoticeImage, updateNotice, withdrawNotice } from '../../api/functions';
import { Notice, NoticeCommand } from '../../api/models';
import { WorkspaceService } from '../../core/auth/workspace.service';

@Component({
  selector: 'app-notice-admin', imports: [FormsModule, RouterLink], providers: [WorkspaceService],
  template: `
    <section aria-labelledby="publication-title">
      <nav><a routerLink="/dashboard">Mi sesión</a> · <a routerLink="/notices">Avisos públicos</a></nav>
      <h2 id="publication-title">Administración de publicaciones</h2>
      @if (w.message()) { <p role="alert">{{ w.message() }}</p> }
      <form (ngSubmit)="load()">
        <label>Buscar <input name="query" [(ngModel)]="search" maxlength="100" /></label>
        <label>Estado <select name="status" [(ngModel)]="status"><option value="">Todos</option><option>BORRADOR</option><option>PUBLICADO</option><option>RETIRADO</option></select></label>
        <button [disabled]="w.busy()">Filtrar</button>
      </form>
      <button type="button" (click)="newNotice()" [disabled]="w.busy()">Nuevo aviso</button>
      <ul>@for (notice of notices(); track notice.id) { <li><button type="button" (click)="open(notice.id)" [disabled]="w.busy()">{{ notice.title }} · {{ notice.status }}</button></li> } @empty { <li>No hay avisos registrados.</li> }</ul>
      @if (cursor()) { <button type="button" (click)="load(true)" [disabled]="w.busy()">Ver más</button> }
      <h3>{{ current() ? 'Editar aviso' : 'Crear borrador' }}</h3>
      <form (ngSubmit)="save()">
        <fieldset [disabled]="w.busy()">
          <label>Tipo <select name="type" [(ngModel)]="form.type"><option value="RESIDENCIAS">Residencias</option><option value="SERVICIO_SOCIAL">Servicio social</option><option value="EMPLEO">Empleo</option></select></label>
          <label>Título <input name="title" [(ngModel)]="form.title" required maxlength="200" /></label>
          <label>Descripción <textarea name="description" [(ngModel)]="form.description" required maxlength="12000"></textarea></label>
          <label>Requisitos <textarea name="requirements" [(ngModel)]="form.requirements" maxlength="6000"></textarea></label>
          <p>El contenido se guarda como texto; no admite HTML.</p>
          <label>Enlace externo HTTPS <input name="url" [(ngModel)]="form.url" type="url" maxlength="2048" /></label>
          <label>Inicio de publicación (hora local) <input name="startsAt" [(ngModel)]="startsAt" type="datetime-local" /></label>
          <label>Fin de vigencia (hora local, opcional) <input name="endsAt" [(ngModel)]="endsAt" type="datetime-local" /></label>
          <button type="submit">{{ current() ? 'Guardar cambios' : 'Guardar borrador' }}</button>
        </fieldset>
      </form>
      <fieldset [disabled]="w.busy()">
        <legend>Imagen opcional</legend><p>JPG, PNG o WebP; máximo 2 MB, 4096 píxeles por lado y 4 millones de píxeles. Se escanea y normaliza antes de adjuntarse.</p>
        <label>Seleccionar imagen <input type="file" accept="image/jpeg,image/png,image/webp" (change)="selectFile($event)" /></label>
        <button type="button" [disabled]="!file" (click)="upload()">Validar imagen</button>
        @if (form.imageId) { <p role="status">Imagen validada. Guarda el aviso para adjuntarla.</p><button type="button" (click)="form.imageId = null">Quitar imagen</button> }
      </fieldset>
      @if (current(); as notice) {
        <p>Estado: {{ notice.status }} · Versión {{ notice.version }}. Publicar requiere un inicio válido y cambios guardados.</p>
        <button type="button" (click)="transition('publish')" [disabled]="w.busy() || notice.status === 'PUBLICADO' || !notice.publicationWindow.startsAt">Publicar aviso</button>
        <button type="button" (click)="transition('withdraw')" [disabled]="w.busy() || notice.status !== 'PUBLICADO'">Retirar aviso</button>
      }
    </section>
  `,
})
export class NoticeAdmin {
  readonly w = inject(WorkspaceService);
  readonly notices = signal<Notice[]>([]);
  readonly current = signal<Notice | null>(null);
  readonly cursor = signal<string | null>(null);
  form: NoticeCommand = this.blank();
  startsAt = ''; endsAt = ''; search = ''; status = ''; file: File | null = null;
  private uploadKey = ''; private lastPayload = ''; private commandKey = ''; private readonly transitionKeys = new Map<string, string>();

  constructor() { this.load(); }
  private blank(): NoticeCommand { return { type: 'RESIDENCIAS', title: '', description: '', requirements: '', url: null, imageId: null, publicationWindow: { startsAt: null, endsAt: null } }; }
  newNotice(): void { this.current.set(null); this.form = this.blank(); this.startsAt = ''; this.endsAt = ''; this.file = null; this.lastPayload = ''; }
  load(more = false): void {
    const status = ['BORRADOR', 'PUBLICADO', 'RETIRADO'].includes(this.status) ? this.status as Notice['status'] : undefined;
    this.w.run(this.w.api.invoke(listAdministrativeNotices, { 'page[size]': 25, 'page[after]': more ? this.cursor() ?? undefined : undefined, 'filter[status]': status, q: this.search }), response => { this.notices.set(more ? [...this.notices(), ...response.data] : response.data); this.cursor.set(response.page.nextCursor); });
  }
  open(id: string): void { this.w.run(this.w.api.invoke(getAdministrativeNotice, { notice: id }), response => this.accept(response.data)); }
  private accept(notice: Notice): void {
    this.current.set(notice); this.form = { type: notice.type, title: notice.title, description: notice.description, requirements: notice.requirements, url: notice.url, imageId: notice.resources[0]?.id ?? null, publicationWindow: notice.publicationWindow };
    this.startsAt = this.local(notice.publicationWindow.startsAt); this.endsAt = this.local(notice.publicationWindow.endsAt); this.file = null;
  }
  private local(value: string | null): string { if (!value) return ''; const date = new Date(value); return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16); }
  save(): void {
    const current = this.current();
    const body: NoticeCommand = { ...this.form, url: this.form.url || null, publicationWindow: { startsAt: this.startsAt ? new Date(this.startsAt).toISOString() : null, endsAt: this.endsAt ? new Date(this.endsAt).toISOString() : null } };
    const fingerprint = JSON.stringify(body);
    if (fingerprint !== this.lastPayload) { this.lastPayload = fingerprint; this.commandKey = crypto.randomUUID(); }
    this.w.run(this.w.csrf(token => current
      ? this.w.api.invoke(updateNotice, { notice: current.id, 'If-Match': '"'+current.version+'"', 'X-XSRF-TOKEN': token, body })
      : this.w.api.invoke(createNotice, { 'Idempotency-Key': this.commandKey, 'X-XSRF-TOKEN': token, body })), response => { this.accept(response.data); this.load(); }, () => { if (current) this.open(current.id); });
  }
  selectFile(event: Event): void { this.file = (event.target as HTMLInputElement).files?.[0] ?? null; this.uploadKey = crypto.randomUUID(); }
  upload(): void {
    if (!this.file) return;
    if (this.file.size > 2 * 1024 * 1024) { this.w.message.set('La imagen excede 2 MB.'); return; }
    const file = this.file;
    this.w.run(this.w.csrf(token => this.w.api.invoke(stageNoticeImage, { 'X-XSRF-TOKEN': token, 'Idempotency-Key': this.uploadKey, body: { file, purpose: 'NOTICE_IMAGE' } })), result => { this.form.imageId = result.data.id; this.file = null; });
  }
  transition(action: 'publish' | 'withdraw'): void {
    const current = this.current(); if (!current) return;
    const cacheKey = current.id+':'+current.version+':'+action;
    const key = this.transitionKeys.get(cacheKey) ?? crypto.randomUUID(); this.transitionKeys.set(cacheKey, key);
    this.w.run(this.w.csrf(token => this.w.api.invoke(action === 'publish' ? publishNotice : withdrawNotice,
      { notice: current.id, 'If-Match': '"'+current.version+'"', 'Idempotency-Key': key, 'X-XSRF-TOKEN': token, body: {} })), response => { this.accept(response.data); this.load(); }, () => this.open(current.id));
  }
}
