<?php

namespace App\Modules\Residencies\Infrastructure\Persistence;

use App\Modules\IAM\Application\AuthorizationDirectory;
use App\Modules\Residencies\Application\DocumentWorkflow;
use App\Modules\Residencies\Application\EvidenceWorkflow;
use App\Modules\Residencies\Application\ProgressWorkflow;
use App\Modules\Residencies\Domain\Services\DocumentRules;
use App\Modules\Residencies\Domain\Services\EvidenceRules;
use App\Modules\Residencies\Domain\Services\ProgressRules;
use App\Modules\Shared\Application\Ports\ScannedFiles;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ProgressStore implements ProgressWorkflow
{
    public function __construct(private AuthorizationDirectory $authorization, private EvidenceWorkflow $evidence,
        private DocumentWorkflow $documents, private ScannedFiles $files) {}

    private function table(string $kind): Builder
    {
        return DB::table('residencias_'.$kind);
    }

    private function find(string $kind, string $id): object
    {
        $row = $this->table($kind)->where('id', $id)->first();
        abort_unless($row !== null, 404);

        return $row;
    }

    private function gate(bool $write): void
    {
        $query = $this->table('controles')->where('codigo', 'WRITER');
        ($write ? $query->lockForUpdate() : $query->sharedLock())->firstOrFail();
    }

    /**
     * @return array{object,object} */
    private function project(string $id, string $actor, string $permission = 'files.read'): array
    {
        $project = $this->find('proyectos', $id);
        $resident = $this->find('residentes', $project->id_residente);
        abort_unless($this->authorization->allows($actor, 'residencies.'.$permission, $resident->id_identidad), 403);

        return [$project, $resident];
    }

    private function audit(string $action, string $id, string $actor, string $correlation): void
    {
        DB::table('compartido_registros_auditoria')->insert(['id' => (string) Str::ulid(), 'ocurrido_en' => now(), 'id_identidad_actor' => $actor,
            'nombre_contexto' => 'Residencies', 'accion' => $action, 'tipo_sujeto' => 'Completion', 'id_sujeto' => $id, 'id_correlacion' => $correlation]);
    }

    /**
     * @return list<object> */
    private function reports(string $project): array
    {
        return $this->table('reportes_avance as r')->leftJoin('residencias_entregas_evidencia as e', 'e.id', '=', 'r.id_entrega')
            ->leftJoin('residencias_revisiones_evidencia as v', fn ($join) => $join->on('v.id_entrega', '=', 'e.id')->on('v.numero_revision', '=', 'e.cantidad_revisiones'))
            ->where('r.id_proyecto', $project)->orderByRaw("CASE WHEN r.tipo_reporte = 'PARCIAL' THEN 0 ELSE 1 END")->orderBy('r.numero_consecutivo')
            ->get(['r.*', 'e.estado as estado_entrega', 'e.numero_version as version_entrega', 'e.cantidad_revisiones', 'e.id_identidad_envio',
                'e.sha256', 'e.tipo_mime', 'e.tamano_bytes', 'e.id_archivo', 'e.dependencias_reporte', 'v.calificacion', 'v.observaciones as observaciones_revision'])->all();
    }

    /**
     * @param list<object> $reports */
    private function precedingApproved(object $slot, array $reports): bool
    {
        foreach ($reports as $row) {
            if ($row->tipo_reporte === 'PARCIAL' && ($slot->tipo_reporte === 'FINAL' || $row->numero_consecutivo < $slot->numero_consecutivo)
                && ($row->estado_entrega !== 'APROBADO' || ! $this->dependenciesCurrent($row, $reports))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<object> $reports */
    private function dependenciesCurrent(object $row, array $reports): bool
    {
        $map = fn (object $r): array => ['type' => $r->tipo_reporte, 'sequence' => (int) $r->numero_consecutivo, 'evidenceId' => $r->id_entrega,
            'reviewNumber' => (int) ($r->cantidad_revisiones ?? 0), 'dependencies' => $r->dependencias_reporte];

        return ProgressRules::dependenciesCurrent(array_map($map, $reports), $map($row));
    }

    /**
     * @param list<object> $reports
     * @return array<string,mixed> */
    private function report(object $row, object $project, object $resident, string $actor, array $reports): array
    {
        $submit = $this->authorization->allows($actor, 'residencies.reports.submit', $resident->id_identidad);
        $review = $this->authorization->allows($actor, 'residencies.reports.review');

        return ['id' => $row->id, 'projectId' => $project->id, 'residentId' => $resident->id, 'type' => $row->tipo_reporte, 'sequence' => (int) $row->numero_consecutivo,
            'requirementId' => $row->id_requisito, 'version' => (int) $row->version, 'startsOn' => $row->fecha_inicio, 'endsOn' => $row->fecha_fin,
            'evidenceId' => $row->id_entrega, 'status' => $row->estado_entrega ?? 'SIN_ENTREGA', 'submissionVersion' => (int) ($row->version_entrega ?? 0),
            'reviewNumber' => (int) ($row->cantidad_revisiones ?? 0), 'grade' => $row->calificacion === null ? null : (float) $row->calificacion,
            'observations' => $row->observaciones_revision, 'canSubmit' => $submit && $resident->estado === 'ACTIVO' && $project->estado === 'APROBADO'
                && $this->find('periodos', $project->id_periodo)->estado === 'ACTIVO' && ($row->estado_entrega === null || EvidenceRules::replaceable($row->estado_entrega)) && $this->precedingApproved($row, $reports),
            'dependenciesCurrent' => $this->dependenciesCurrent($row, $reports),
            'canReview' => $review && $row->estado_entrega === 'ENVIADO' && $actor !== $resident->id_identidad && $actor !== $row->id_identidad_envio];
    }

    public function read(string $operation, ?string $resource, array $filters, string $actor): array
    {
        return DB::transaction(fn (): array => $this->readTransaction($operation, $resource, $filters, $actor));
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    private function readTransaction(string $operation, ?string $resource, array $filters, string $actor): array
    {
        $this->gate(false);
        if ($operation === 'report-queue') {
            abort_unless($this->authorization->allows($actor, 'residencies.reports.review'), 403);
            $query = $this->table('reportes_avance as r')->join('residencias_entregas_evidencia as e', 'e.id', '=', 'r.id_entrega')
                ->join('residencias_proyectos as p', 'p.id', '=', 'r.id_proyecto')->join('residencias_residentes as s', 's.id', '=', 'p.id_residente')
                ->where('e.estado', 'ENVIADO')->where('e.id_identidad_envio', '!=', $actor)->where('s.id_identidad', '!=', $actor);
            if (isset($filters['projectId'])) {
                $query->where('p.id', $filters['projectId']);
            }
            [$rows,$next] = $this->page($query->select(['r.id', 'r.id_proyecto', 'r.tipo_reporte', 'r.numero_consecutivo', 'r.id_entrega', 'e.cantidad_revisiones', 'p.nombre as proyecto_nombre', 's.nombres', 's.primer_apellido', 's.segundo_apellido']), $operation, $resource, $filters, $actor, 'r.id');

            return ['items' => array_map(fn (object $r): array => ['id' => $r->id, 'projectId' => $r->id_proyecto, 'type' => $r->tipo_reporte, 'sequence' => (int) $r->numero_consecutivo,
                'evidenceId' => $r->id_entrega, 'reviewNumber' => (int) $r->cantidad_revisiones, 'projectName' => $r->proyecto_nombre,
                'residentName' => trim($r->nombres.' '.$r->primer_apellido.' '.($r->segundo_apellido ?? ''))], $rows), 'nextCursor' => $next];
        }
        if ($operation === 'reopening-history') {
            $delivery = $this->find('entregas_evidencia', (string) $resource);
            $resident = $this->find('residentes', $delivery->id_residente);
            abort_unless($this->authorization->allows($actor, 'residencies.files.read', $resident->id_identidad), 403);
            [$rows,$next] = $this->page($this->table('reaperturas_evidencia')->where('id_entrega', $resource), $operation, $resource, $filters, $actor);

            return ['items' => array_map(fn (object $r): array => ['id' => $r->id, 'number' => (int) $r->numero_reapertura, 'previousReviewNumber' => (int) $r->numero_revision_anterior,
                'previousStatus' => $r->estado_anterior, 'observations' => $r->observaciones, 'occurredAt' => $r->creado_en], $rows), 'nextCursor' => $next,
                'canReopen' => $this->authorization->allows($actor, 'residencies.evidence.reopen')
                    && $actor !== $resident->id_identidad && $actor !== $delivery->id_identidad_envio
                    && in_array($delivery->estado, ['APROBADO', 'RECHAZADO'], true) && ! $this->hasApprovedRelease($delivery)];
        }
        [$project,$resident] = $this->project((string) $resource, $actor);
        $reports = $this->reports($project->id);
        if ($operation === 'plan') {
            $period = $this->find('periodos', $project->id_periodo);

            return ['projectId' => $project->id, 'projectVersion' => (int) $project->version, 'projectStatus' => $project->estado,
                'partialReports' => (int) $period->cantidad_reportes_parciales, 'finalReports' => 1, 'initialized' => $reports !== [],
                'plannedWindows' => ProgressRules::plan((int) $period->duracion_meses, $project->fecha_inicio, $project->fecha_fin),
                'items' => array_map(fn (object $row): array => $this->report($row, $project, $resident, $actor, $reports), $reports)];
        }
        $release = $this->table('liberaciones')->where('id_proyecto', $project->id)->first();
        if ($operation === 'release-history') {
            if (! $release) {
                return ['items' => [], 'nextCursor' => null];
            }
            [$rows,$next] = $this->page($this->table('historial_liberaciones')->where('id_liberacion', $release->id), $operation, $resource, $filters, $actor);

            return ['items' => array_map(fn (object $r): array => ['id' => $r->id, 'number' => (int) $r->numero_cambio, 'previousStatus' => $r->estado_anterior,
                'status' => $r->estado_nuevo, 'resolution' => $r->resolucion, 'observations' => $r->observaciones, 'occurredAt' => $r->cambiado_en], $rows), 'nextCursor' => $next];
        }
        abort_unless($operation === 'release', 404);
        $eligibility = $this->eligibility($project, false);
        foreach (['requirements', 'reports'] as $kind) {
            $eligibility[$kind] = array_map(fn (array $row): array => array_diff_key($row, ['uploader' => true, 'sha256' => true]), $eligibility[$kind]);
        }

        return $this->release($release, $project) + ['eligibility' => $eligibility];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{list<object>,?string} */
    private function page(Builder $query, string $operation, ?string $resource, array $filters, string $actor, string $column = 'id'): array
    {
        $size = (int) ($filters['pageSize'] ?? 25);
        abort_unless($size >= 1 && $size <= 100, 422);
        $binding = hash('sha256', DocumentRules::canonical(['operation' => $operation, 'resource' => $resource, 'actor' => $actor, 'size' => $size, 'project' => $filters['projectId'] ?? null]));
        if (isset($filters['cursor'])) {
            try {
                $cursor = json_decode(Crypt::decryptString($filters['cursor']), true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                abort(422);
            }
            abort_unless(is_array($cursor) && ($cursor['binding'] ?? '') === $binding && Str::isUlid($cursor['id'] ?? ''), 422);
            $query->where($column, '>', $cursor['id']);
        }
        $rows = $query->orderBy($column)->limit($size + 1)->get()->all();
        $more = count($rows) > $size;
        if ($more) {
            array_pop($rows);
        }$last = $rows === [] ? null : $rows[count($rows) - 1];

        return [$rows, $more && $last ? Crypt::encryptString(json_encode(['binding' => $binding, 'id' => $last->id], JSON_THROW_ON_ERROR)) : null];
    }

    public function execute(string $operation, string $resource, array $input, string $key, string $actor, string $correlation): array
    {
        abort_unless((bool) preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key), 422);

        return DB::transaction(function () use ($operation, $resource, $input, $key, $actor, $correlation): array {
            $this->gate(true);
            $permissions = ['plan-initialize' => 'reports.submit', 'project-approve' => 'release.resolve', 'report-submit' => 'reports.submit',
                'report-review' => 'reports.review', 'release-resolve' => 'release.resolve', 'release-reopen' => 'release.reopen', 'evidence-reopen' => 'evidence.reopen'];
            abort_unless(isset($permissions[$operation]), 404);
            if ($operation === 'evidence-reopen') {
                $delivery = $this->find('entregas_evidencia', $resource);
                $resident = $this->find('residentes', $delivery->id_residente);
                abort_unless($this->authorization->allows($actor, 'residencies.'.$permissions[$operation]), 403);
                abort_if($actor === $resident->id_identidad || $actor === $delivery->id_identidad_envio, 403);
            } else {
                [$project,$resident] = $this->project($resource, $actor, $permissions[$operation]);
            }
            if (in_array($operation, ['project-approve', 'release-resolve', 'release-reopen'], true)) {
                abort_if($resident->id_identidad === $actor, 403);
            }
            $fingerprint = hash('sha256', DocumentRules::canonical(['op' => $operation, 'id' => $resource, 'input' => $input]));
            $hashed = hash('sha256', $actor.'|completion|'.$operation.'|'.$resource.'|'.$key);
            $old = $this->table('claves_idempotencia')->where('clave', $hashed)->first();
            if ($old) {
                abort_unless(hash_equals($old->hash_solicitud, $fingerprint) && $old->expira_en > now()->format('Y-m-d H:i:s.u'), 409);

                return json_decode($old->cuerpo_respuesta, true, flags: JSON_THROW_ON_ERROR);
            }
            $result = match ($operation) {
                'plan-initialize' => $this->initialize($project, $resident),
                'project-approve' => $this->approve($project, $actor, $input),
                'report-submit' => $this->submit($project, $resident, $input, $key, $actor, $correlation),
                'report-review' => $this->review($project, $input, $key, $actor, $correlation),
                'release-resolve','release-reopen' => $this->resolve($operation, $project, $input, $actor, $correlation),
                'evidence-reopen' => $this->reopenEvidence($delivery, $input, $actor)
            };
            $this->audit(strtoupper(str_replace('-', '_', $operation)), $resource, $actor, $correlation);
            $this->table('claves_idempotencia')->insert(['id' => (string) Str::ulid(), 'clave' => $hashed, 'hash_solicitud' => $fingerprint,
                'cuerpo_respuesta' => json_encode($result, JSON_THROW_ON_ERROR), 'expira_en' => now()->addDay()]);

            return $result;
        }, 3);
    }

    /**
     * @return array<string,mixed> */
    private function initialize(object $project, object $resident): array
    {
        abort_unless($resident->estado === 'ACTIVO' && in_array($project->estado, ['BORRADOR', 'APROBADO'], true), 409);
        $period = $this->find('periodos', $project->id_periodo);
        abort_unless($period->estado === 'ACTIVO', 409);
        if ($this->table('reportes_avance')->where('id_proyecto', $project->id)->exists()) {
            return ['projectId' => $project->id, 'initialized' => true];
        }
        foreach (ProgressRules::plan((int) $period->duracion_meses, $project->fecha_inicio, $project->fecha_fin) as $window) {
            $definition = (string) Str::ulid();
            $name = 'Reporte '.strtolower($window['type']).' '.$window['sequence'];
            $this->table('definiciones_requisito')->insert(['id' => $definition, 'id_programa' => $project->id_programa, 'id_proyecto' => $project->id,
                'codigo' => 'SYS-RPT-'.$project->id.'-'.$window['type'].'-'.$window['sequence'], 'nombre' => $name, 'etapa' => 'REQUISITO', 'tipo_evidencia' => 'REPORTE',
                'alcance' => 'PROYECTO', 'obligatorio' => true, 'orden_visualizacion' => $window['sequence'], 'activo' => true,
                'mimes_permitidos' => json_encode(['application/pdf'], JSON_THROW_ON_ERROR), 'tamano_maximo' => 10485760, 'version' => 1, 'creado_en' => now(), 'actualizado_en' => now()]);
            $this->table('reportes_avance')->insert(['id' => (string) Str::ulid(), 'id_proyecto' => $project->id, 'id_requisito' => $definition, 'tipo_reporte' => $window['type'],
                'numero_consecutivo' => $window['sequence'], 'titulo' => $name, 'fecha_inicio' => $window['startsOn'], 'fecha_fin' => $window['endsOn'], 'version' => 1, 'creado_en' => now()]);
        }

        return ['projectId' => $project->id, 'initialized' => true];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> */
    private function approve(object $project, string $actor, array $input): array
    {
        abort_unless($project->estado === 'BORRADOR' && (int) $project->version === (int) $input['expectedVersion'], 409);
        abort_unless($this->find('residentes', $project->id_residente)->estado === 'ACTIVO' && $this->find('periodos', $project->id_periodo)->estado === 'ACTIVO'
            && $this->find('empresas', $project->id_empresa)->estado === 'ACTIVO', 409);
        $eligibility = $this->eligibility($project, true);
        abort_unless($eligibility['requirementsComplete'] && $eligibility['advisersComplete'], 409);
        abort_if(in_array($actor, array_column($eligibility['requirements'], 'uploader'), true), 403);
        $this->table('proyectos')->where('id', $project->id)->update(['estado' => 'APROBADO', 'id_identidad_aprobador' => $actor, 'aprobado_en' => now(),
            'version' => (int) $project->version + 1, 'actualizado_en' => now()]);

        return ['projectId' => $project->id, 'projectStatus' => 'APROBADO', 'projectVersion' => (int) $project->version + 1];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> */
    private function submit(object $project, object $resident, array $input, string $key, string $actor, string $correlation): array
    {
        $slot = $this->table('reportes_avance')->where('id_proyecto', $project->id)->where('tipo_reporte', $input['type'])->where('numero_consecutivo', $input['sequence'])->first();
        abort_unless($slot !== null, 409);
        $delivery = $this->evidence->execute('submit-report', null, ['residentId' => $resident->id, 'projectId' => $project->id, 'requirementId' => $slot->id_requisito,
            'stagedFileId' => $input['stagedFileId'], 'expectedSubmissionVersion' => $input['expectedSubmissionVersion']], null, $key, $actor, $correlation);
        $this->table('reportes_avance')->where('id', $slot->id)->update(['id_entrega' => $delivery['id'], 'entregado_en' => now(), 'version' => (int) $slot->version + 1]);
        $rows = $this->reports($project->id);
        $current = array_values(array_filter($rows, fn (object $r): bool => $r->id === $slot->id))[0];

        return $this->report($current, $project, $resident, $actor, $rows);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> */
    private function review(object $project, array $input, string $key, string $actor, string $correlation): array
    {
        $slot = $this->table('reportes_avance')->where('id_proyecto', $project->id)->where('id_entrega', $input['evidenceId'])->first();
        abort_unless($slot !== null, 404);

        return $this->evidence->execute('review', $input['evidenceId'], array_diff_key($input, ['evidenceId' => true]), null, $key, $actor, $correlation);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> */
    private function reopenEvidence(object $delivery, array $input, string $actor): array
    {
        abort_unless(in_array($delivery->estado, ['APROBADO', 'RECHAZADO'], true) && (int) $delivery->cantidad_revisiones === (int) $input['expectedReviewNumber'], 409);
        $this->evidence->read('evidence', $delivery->id, [], $actor);
        abort_if($this->hasApprovedRelease($delivery), 409);
        $reason = ProgressRules::reason($input['observations']);
        $number = (int) $this->table('reaperturas_evidencia')->where('id_entrega', $delivery->id)->max('numero_reapertura') + 1;
        $this->table('reaperturas_evidencia')->insert(['id' => (string) Str::ulid(), 'id_entrega' => $delivery->id, 'numero_reapertura' => $number,
            'numero_revision_anterior' => $delivery->cantidad_revisiones, 'estado_anterior' => $delivery->estado, 'observaciones' => $reason, 'id_identidad_actor' => $actor, 'creado_en' => now()]);
        $this->table('entregas_evidencia')->where('id', $delivery->id)->update(['estado' => 'ENVIADO', 'version' => (int) $delivery->version + 1]);

        return ['evidenceId' => $delivery->id, 'status' => 'ENVIADO', 'reviewNumber' => (int) $delivery->cantidad_revisiones, 'reopeningNumber' => $number];
    }

    private function hasApprovedRelease(object $delivery): bool
    {
        return $this->table('liberaciones as l')->join('residencias_proyectos as p', 'p.id', '=', 'l.id_proyecto')->where('l.estado', 'APROBADO')
            ->where('p.id_residente', $delivery->id_residente)->when($delivery->id_proyecto !== null, fn (Builder $q) => $q->where('p.id', $delivery->id_proyecto))->exists();
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> */
    private function resolve(string $operation, object $project, array $input, string $actor, string $correlation): array
    {
        $row = $this->table('liberaciones')->where('id_proyecto', $project->id)->first();
        $version = $row ? (int) $row->version : 0;
        abort_unless($version === (int) $input['expectedVersion'], 409);
        $reason = ProgressRules::reason($input['observations']);
        if ($operation === 'release-reopen') {
            abort_unless($row && in_array($row->estado, ['APROBADO', 'RECHAZADO'], true), 409);
            $state = 'PENDIENTE';
            $eligibility = $this->eligibility($project, false);
            $resolution = null;
            $this->documents->invalidateForProject($project->id, $reason, $actor, $correlation);
            if ($project->estado === 'COMPLETADO') {
                $this->table('proyectos')->where('id', $project->id)->update(['estado' => 'APROBADO', 'version' => (int) $project->version + 1, 'actualizado_en' => now()]);
            }
        } else {
            abort_unless($project->estado === 'APROBADO' && ($row === null || $row->estado === 'PENDIENTE'), 409);
            $state = $input['decision'];
            $eligibility = $this->eligibility($project, false);
            $resolution = $input['resolution'] ?? null;
            if ($state === 'APROBADO') {
                abort_unless($eligibility['eligible'], 409);
                abort_if(in_array($actor, array_column([...$eligibility['requirements'], ...$eligibility['reports']], 'uploader'), true), 403);
                $this->table('proyectos')->where('id', $project->id)->update(['estado' => 'COMPLETADO', 'version' => (int) $project->version + 1, 'actualizado_en' => now()]);
            }
        }
        $final = $this->table('reportes_avance')->where('id_proyecto', $project->id)->where('tipo_reporte', 'FINAL')->first();
        abort_unless($final !== null, 409);
        $id = $row->id ?? (string) Str::ulid();
        $values = ['estado' => $state, 'resolucion' => $resolution, 'observaciones' => $reason, 'id_identidad_resolutor' => $actor, 'resuelto_en' => now(),
            'version' => $version + 1, 'actualizado_en' => now(), 'datos_elegibilidad' => DocumentRules::canonical($eligibility)];
        if ($row) {
            $this->table('liberaciones')->where('id', $id)->update($values);
        } else {
            $this->table('liberaciones')->insert($values + ['id' => $id, 'id_proyecto' => $project->id, 'id_reporte_final' => $final->id, 'creado_en' => now()]);
        }
        $this->table('historial_liberaciones')->insert(['id' => (string) Str::ulid(), 'id_liberacion' => $id, 'numero_cambio' => $version + 1, 'estado_anterior' => $row?->estado,
            'estado_nuevo' => $state, 'resolucion' => $resolution, 'observaciones' => $reason, 'id_identidad_actor' => $actor, 'cambiado_en' => now(), 'datos_elegibilidad' => DocumentRules::canonical($eligibility)]);
        $event = (string) Str::ulid();
        $type = 'residencies.release-changed.v1';
        DB::table('compartido_mensajes_salida')->insert(['id' => $event, 'nombre_contexto' => 'Residencies', 'tipo_agregado' => 'Release', 'id_agregado' => $id, 'tipo_evento' => $type,
            'contenido' => json_encode(['eventId' => $event, 'type' => $type, 'version' => 1, 'aggregateId' => $id, 'actor' => $actor, 'correlationId' => $correlation, 'occurredAt' => now()->toISOString(),
                'data' => ['projectId' => $project->id, 'status' => $state, 'releaseVersion' => $version + 1]], JSON_THROW_ON_ERROR), 'estado' => 'PENDING', 'ocurrido_en' => now(), 'disponible_en' => now()]);

        return $this->release($this->find('liberaciones', $id), $this->find('proyectos', $project->id));
    }

    /**
     * @return array<string,mixed> */
    private function release(?object $row, object $project): array
    {
        return ['id' => $row?->id, 'projectId' => $project->id, 'projectStatus' => $project->estado, 'version' => (int) ($row->version ?? 0), 'status' => $row->estado ?? 'PENDIENTE',
            'resolution' => $row?->resolucion, 'observations' => $row?->observaciones, 'resolvedAt' => $row?->resuelto_en];
    }

    /**
     * @return array<string,mixed> */
    private function eligibility(object $project, bool $prerequisitesOnly): array
    {
        $resident = $this->find('residentes', $project->id_residente);
        $today = now('America/Mexico_City')->toDateString();
        $definitions = $this->table('definiciones_requisito')->where('tipo_evidencia', 'DOCUMENTO')->where('activo', true)->where('obligatorio', true)
            ->where(fn (Builder $q) => $q->whereNull('id_programa')->orWhere('id_programa', $project->id_programa))
            ->when($prerequisitesOnly, fn (Builder $q) => $q->where('etapa', 'PRERREQUISITO'))->get()->filter(fn (object $r): bool => EvidenceRules::inForce($r->vigente_desde, $r->vigente_hasta, $today));
        $deliveries = $this->table('entregas_evidencia')->where('id_residente', $resident->id)->where(fn (Builder $q) => $q->whereNull('id_proyecto')->orWhere('id_proyecto', $project->id))
            ->orderBy('numero_version')->get()->keyBy(fn (object $r): string => $r->id_requisito.'|'.$r->id_alcance);
        $required = [];
        $complete = true;
        foreach ($definitions as $definition) {
            $scope = $definition->alcance === 'PROYECTO' ? $project->id : $resident->id;
            $row = $deliveries->get($definition->id.'|'.$scope);
            $valid = $row && $row->estado === 'APROBADO' && $this->integrity($row);
            $complete = $complete && $valid;
            $required[] = ['requirementId' => $definition->id, 'name' => $definition->nombre, 'evidenceId' => $row?->id, 'state' => $row->estado ?? 'SIN_ENTREGA',
                'reviewNumber' => (int) ($row->cantidad_revisiones ?? 0), 'sha256' => $row?->sha256, 'uploader' => $row?->id_identidad_envio, 'valid' => (bool) $valid];
        }
        $reports = $this->reports($project->id);
        $period = $this->find('periodos', $project->id_periodo);
        $reportComplete = count($reports) === (int) $period->cantidad_reportes_parciales + 1;
        $reportChecks = [];
        foreach ($reports as $row) {
            $valid = $row->estado_entrega === 'APROBADO' && $row->calificacion !== null && $this->integrity($row) && $this->dependenciesCurrent($row, $reports);
            $reportComplete = $reportComplete && $valid;
            $reportChecks[] = ['id' => $row->id, 'type' => $row->tipo_reporte, 'sequence' => (int) $row->numero_consecutivo,
                'evidenceId' => $row->id_entrega, 'state' => $row->estado_entrega ?? 'SIN_ENTREGA', 'grade' => $row->calificacion === null ? null : (float) $row->calificacion,
                'sha256' => $row->sha256, 'uploader' => $row->id_identidad_envio, 'valid' => $valid];
        }
        $advisers = true;
        foreach (['internos', 'externos'] as $kind) {
            $ranges = $this->table('asesores_'.$kind.'_proyecto')->where('id_proyecto', $project->id)->where('codigo_rol', 'PRINCIPAL')->orderBy('fecha_inicio')->get();
            $cursor = $project->fecha_inicio;
            $covered = false;
            foreach ($ranges as $range) {
                if ($range->fecha_inicio > $cursor) {
                    break;
                }$end = $range->fecha_fin ?? $project->fecha_fin;
                if ($end >= $project->fecha_fin) {
                    $covered = true;
                    break;
                }if ($end >= $cursor) {
                    $cursor = (new \DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d');
                }
            }
            $advisers = $advisers && $covered;
        }

        return ['requirementsComplete' => $complete, 'reportsComplete' => $reportComplete, 'advisersComplete' => $advisers,
            'eligible' => $resident->estado === 'ACTIVO' && in_array($project->estado, ['APROBADO', 'COMPLETADO'], true) && $complete && $reportComplete && $advisers,
            'requirements' => $required, 'reports' => $reportChecks];
    }

    private function integrity(object $row): bool
    {
        if (! $row->id_archivo) {
            return false;
        }
        try {
            $metadata = $this->files->metadata($row->id_archivo);
            $bytes = $this->files->bytes($row->id_archivo);

            return $metadata !== null && $metadata['status'] === 'LIMPIO' && $metadata['ownerId'] === $row->id_identidad_envio && $metadata['size'] === (int) $row->tamano_bytes
                && $metadata['mime'] === $row->tipo_mime && hash_equals($metadata['sha256'], $row->sha256) && hash_equals(hash('sha256', $bytes['content']), $row->sha256);
        } catch (Throwable) {
            return false;
        }
    }
}
