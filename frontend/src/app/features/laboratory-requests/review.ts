import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import {
  cancelLaboratoryRequest, decideLaboratoryRequest, downloadLaboratoryReceipt, getLaboratoryDecisionHistory,
  getLaboratoryOccupancy, getLaboratoryReceipt, getLaboratoryWorkflow, listLaboratoryNotifications,
  listLaboratoryReviewQueue, listLaboratoryWorkflow, listPlanningLaboratory,
} from '../../api/functions';
import { LaboratoryDecisionHistory, LaboratoryNotificationPage, LaboratoryOccupancyResponse, LaboratoryReceiptResponse, LaboratoryWorkflowRecord, PlanningRecord } from '../../api/models';
import { WorkspaceService } from '../../core/auth/workspace.service';

@Component({
  selector: 'app-laboratory-review',
  imports: [FormsModule, RouterLink],
  providers: [WorkspaceService],
  templateUrl: './review.html',
})
export class LaboratoryReview {
  protected readonly work = inject(WorkspaceService);
  protected readonly mine = signal<LaboratoryWorkflowRecord[]>([]);
  protected readonly queue = signal<LaboratoryWorkflowRecord[]>([]);
  protected readonly selected = signal<LaboratoryWorkflowRecord | null>(null);
  protected readonly history = signal<LaboratoryDecisionHistory['data']['items']>([]);
  protected readonly receipt = signal<LaboratoryReceiptResponse['data'] | null>(null);
  protected readonly notifications = signal<LaboratoryNotificationPage['data']['items']>([]);
  protected readonly occupied = signal<LaboratoryOccupancyResponse['data']['items'] | null>(null);
  protected readonly laboratories = signal<PlanningRecord[]>([]);
  protected readonly laboratoryCursor = signal<string | null>(null);
  protected readonly mineCursor = signal<string | null>(null);
  protected readonly queueCursor = signal<string | null>(null);
  protected readonly notificationCursor = signal<string | null>(null);
  protected readonly notice = signal('');
  protected laboratory = '';
  protected day = '';
  protected reason = '';
  protected decision: 'AUTORIZADO' | 'RECHAZADO' = 'AUTORIZADO';
  private retry: {fingerprint: string; key: string} | null = null;
  private readonly dates = new Intl.DateTimeFormat('es-MX', {timeZone: 'America/Mexico_City', dateStyle: 'medium', timeStyle: 'short'});

  constructor() {
    this.loadMine();
    this.loadNotifications();
    this.loadLaboratories();
    if (this.reviewer()) this.loadQueue();
    const id = inject(ActivatedRoute).snapshot.queryParamMap.get('requestId');
    if (id) this.open(id);
  }

  protected reviewer(): boolean { return this.work.can('laboratorios.solicitudes.revisar'); }
  protected displayDate(value: string): string { return this.dates.format(new Date(value)); }
  protected canDecide(): boolean {
    const row = this.selected();
    return this.reviewer() && row?.status === 'PENDIENTE' && row.ownerId !== this.work.auth.identity()?.id;
  }
  protected canCancel(): boolean {
    const row = this.selected();
    return !!row && ['BORRADOR', 'PENDIENTE', 'AUTORIZADO'].includes(row.status) && new Date(row.startAt).getTime() > Date.now()
      && (this.reviewer() || row.ownerId === this.work.auth.identity()?.id);
  }
  protected loadLaboratories(more = false): void {
    this.work.run(this.work.api.invoke(listPlanningLaboratory, {cursor: more ? this.laboratoryCursor() ?? undefined : undefined}), response => {
      this.laboratories.set(more ? [...this.laboratories(), ...response.data.items] : response.data.items);
      this.laboratoryCursor.set(response.data.nextCursor);
    });
  }
  protected loadMine(more = false): void {
    this.work.run(this.work.api.invoke(listLaboratoryWorkflow, {cursor: more ? this.mineCursor() ?? undefined : undefined}), response => {
      this.mine.set(more ? [...this.mine(), ...response.data.items] : response.data.items);
      this.mineCursor.set(response.data.nextCursor);
    });
  }
  protected loadQueue(more = false): void {
    if (!this.reviewer()) return;
    this.work.run(this.work.api.invoke(listLaboratoryReviewQueue, {cursor: more ? this.queueCursor() ?? undefined : undefined}), response => {
      this.queue.set(more ? [...this.queue(), ...response.data.items] : response.data.items);
      this.queueCursor.set(response.data.nextCursor);
    });
  }
  protected loadNotifications(more = false): void {
    this.work.run(this.work.api.invoke(listLaboratoryNotifications, {cursor: more ? this.notificationCursor() ?? undefined : undefined}), response => {
      this.notifications.set(more ? [...this.notifications(), ...response.data.items] : response.data.items);
      this.notificationCursor.set(response.data.nextCursor);
    });
  }
  protected open(id: string): void {
    this.selected.set(null);
    this.receipt.set(null);
    this.history.set([]);
    this.reason = '';
    this.retry = null;
    this.work.run(this.work.api.invoke(getLaboratoryWorkflow, {record: id}), response => this.select(response.data));
  }
  private select(row: LaboratoryWorkflowRecord): void {
    this.selected.set(row);
    this.work.run(this.work.api.invoke(getLaboratoryDecisionHistory, {record: row.id}), response => {
      this.history.set(response.data.items);
      if (response.data.items.length) this.loadReceipt();
    });
  }
  protected resolve(cancel = false): void {
    const row = this.selected();
    if (!row || this.reason.trim().length < 3 || (cancel ? !this.canCancel() : !this.canDecide())) return;
    const fingerprint = JSON.stringify([row.id, row.version, cancel, this.decision, this.reason.trim()]);
    if (this.retry?.fingerprint !== fingerprint) this.retry = {fingerprint, key: crypto.randomUUID()};
    const headers = {record: row.id, 'If-Match': '"' + row.version + '"', 'Idempotency-Key': this.retry.key};
    const request = this.work.csrf(token => cancel
      ? this.work.api.invoke(cancelLaboratoryRequest, {...headers, 'X-XSRF-TOKEN': token, body: {reason: this.reason.trim()}})
      : this.work.api.invoke(decideLaboratoryRequest, {...headers, 'X-XSRF-TOKEN': token, body: {decision: this.decision, reason: this.reason.trim()}}));
    this.work.run(request, response => {
      this.retry = null;
      this.reason = '';
      this.select(response.data);
      this.notice.set('Cambio guardado. El comprobante y la notificación se preparan en segundo plano.');
      this.loadMine();
      if (this.reviewer()) this.loadQueue();
      if (this.occupied() !== null) this.loadCalendar();
    }, () => this.open(row.id));
  }
  protected loadReceipt(): void {
    const row = this.selected();
    if (row) this.work.run(this.work.api.invoke(getLaboratoryReceipt, {record: row.id}), response => this.receipt.set(response.data));
  }
  protected download(): void {
    const row = this.selected();
    if (!row || this.receipt()?.status !== 'LISTO') return;
    this.work.run(this.work.api.invoke(downloadLaboratoryReceipt, {record: row.id}), blob => {
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = 'comprobante-' + row.id + '.pdf';
      anchor.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
  }
  protected loadCalendar(): void {
    if (!this.laboratory || !this.day) return;
    this.work.run(this.work.api.invoke(getLaboratoryOccupancy, {laboratoryId: this.laboratory,
      startAt: this.day + 'T00:00:00-06:00', endAt: this.day + 'T23:59:59-06:00'}), response => this.occupied.set(response.data.items));
  }
}
