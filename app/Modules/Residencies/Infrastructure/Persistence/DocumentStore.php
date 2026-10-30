<?php

namespace App\Modules\Residencies\Infrastructure\Persistence;

use App\Modules\IAM\Application\AuthorizationDirectory;
use App\Modules\IAM\Application\IdentityDirectory;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Residencies\Application\DocumentWorkflow;
use App\Modules\Residencies\Application\Ports\DocumentRenderer;
use App\Modules\Residencies\Domain\Services\DocumentRules;
use App\Modules\Residencies\Domain\Services\ProgressRules;
use App\Modules\Residencies\Domain\Services\ResidencyRules;
use App\Modules\Residencies\Infrastructure\Jobs\RenderResidencyDocument;
use App\Modules\Shared\Application\Ports\ScannedFiles;
use Composer\InstalledVersions;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DocumentStore implements DocumentWorkflow
{
    public function queueRender(string $id): void
    {
        RenderResidencyDocument::dispatch($id);
    }

    public function __construct(private AuthorizationDirectory $authorization, private OrganizationDirectory $organization,
        private IdentityDirectory $identities, private ScannedFiles $files, private DocumentRenderer $renderer) {}

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

    private function permission(string $actor, string $permission): void
    {
        abort_unless($this->authorization->allows($actor, 'residencies.'.$permission), 403);
    }

    private function audit(string $action, string $id, string $actor, string $correlation): void
    {
        DB::table('compartido_registros_auditoria')->insert(['id' => (string) Str::ulid(), 'ocurrido_en' => now(), 'id_identidad_actor' => $actor,
            'nombre_contexto' => 'Residencies', 'accion' => $action, 'tipo_sujeto' => 'IssuedDocument', 'id_sujeto' => $id, 'id_correlacion' => $correlation]);
    }

    /**
     * @return array{object,object} */
    private function project(string $id, string $actor): array
    {
        $project = $this->find('proyectos', $id);
        $resident = $this->find('residentes', $project->id_residente);
        abort_unless($this->authorization->allows($actor, 'residencies.files.read', $resident->id_identidad), 403);

        return [$project, $resident];
    }

    private function access(object $document, string $actor): void
    {
        $resident = $this->find('residentes', $document->id_residente);
        abort_unless($this->authorization->allows($actor, 'residencies.files.read', $document->tipo_documento === 'CONSTANCIA_ASESORIA' ? null : $resident->id_identidad), 403);
    }

    public function read(string $operation, ?string $resource, array $filters, string $actor): array
    {
        return DB::transaction(function () use ($operation, $resource, $filters, $actor): array {
            $this->gate(false);
            if (in_array($operation, ['templates', 'signers'], true)) {
                $this->permission($actor, 'templates.manage');
                if ($operation === 'signers') {
                    return ['items' => $this->organization->responsibleCandidates($filters['q'] ?? ''), 'nextCursor' => null];
                }
                if ($resource !== null) {
                    return $this->template($this->find('plantillas_documento', $resource));
                }
                $latest = $this->table('plantillas_documento')->select('codigo')->selectRaw('MAX(numero_version) AS numero')->groupBy('codigo');
                $query = $this->table('plantillas_documento as p')->joinSub($latest, 'current', fn ($join) => $join->on('current.codigo', '=', 'p.codigo')->on('current.numero', '=', 'p.numero_version'))->select('p.*');
                [$rows,$next] = $this->page($query, $operation, $resource, $filters, $actor, 'p.id');

                return ['items' => array_map(fn (object $row): array => $this->template($row), $rows), 'nextCursor' => $next];
            }
            if ($operation === 'document') {
                $row = $this->find('documentos_emitidos', (string) $resource);
                $this->access($row, $actor);

                return $this->document($row);
            }
            abort_unless($operation === 'project-documents', 404);
            [$project] = $this->project((string) $resource, $actor);
            $query = $this->table('documentos_emitidos as d')
                ->join('residencias_plantillas_documento as t', 't.id', '=', 'd.id_plantilla')
                ->leftJoin('residencias_constancias as c', 'c.id_documento_emitido', '=', 'd.id')
                ->leftJoin('residencias_anulaciones_documento as a', 'a.id_documento', '=', 'd.id')
                ->select(['d.id', 'd.id_proyecto', 'd.id_plantilla', 'd.numero_version_plantilla', 'd.version', 'd.estado', 'd.bloqueado_hasta',
                    'd.hash_datos_emision', 'd.sha256_archivo', 'd.tamano_archivo', 'd.emitido_en', 't.tipo_documento as metadata_type',
                    'c.folio as metadata_folio', 'a.id as metadata_annul_id', 'a.motivo as metadata_reason', 'a.creado_en as metadata_annulled_at']);
            if ($this->authorization->allows($actor, 'residencies.files.read')) {
                $certificates = $this->table('constancias as c')->join('residencias_proyectos_constancia as p', 'p.id_constancia', '=', 'c.id')->where('p.id_proyecto', $project->id)->select('c.id_documento_emitido');
                $query->where(fn (Builder $q) => $q->where('d.id_proyecto', $project->id)->orWhereIn('d.id', $certificates));
            } else {
                $query->where('d.id_proyecto', $project->id)->where('d.tipo_documento', '!=', 'CONSTANCIA_ASESORIA');
            }
            [$rows,$next] = $this->page($query, $operation, $resource, $filters, $actor, 'd.id');

            return ['items' => array_map(fn (object $row): array => $this->document($row), $rows), 'nextCursor' => $next];
        });
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{list<object>,?string} */
    private function page(Builder $query, string $operation, ?string $resource, array $filters, string $actor, string $column = 'id'): array
    {
        $size = (int) ($filters['pageSize'] ?? 25);
        abort_unless($size >= 1 && $size <= 100, 422);
        $binding = hash('sha256', DocumentRules::canonical(['op' => $operation, 'id' => $resource, 'actor' => $actor, 'size' => $size]));
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

    /**
     * @return array<string,mixed> */
    private function template(object $row): array
    {
        $resources = $this->table('recursos_plantilla')->where('id_plantilla', $row->id)->orderBy('orden_visualizacion')->get();

        return ['id' => $row->id, 'type' => $row->tipo_documento, 'name' => $row->nombre, 'version' => (int) $row->numero_version, 'active' => (bool) $row->activo,
            'configuration' => json_decode($row->configuracion, true, flags: JSON_THROW_ON_ERROR),
            'resources' => $resources->map(fn (object $r): array => ['id' => $r->id, 'order' => (int) $r->orden_visualizacion, 'builtin' => $r->clave_integrada !== null])->all()];
    }

    /**
     * @return array<string,mixed> */
    private function document(object $row): array
    {
        if (property_exists($row, 'metadata_type')) {
            $snapshot = ['type' => $row->metadata_type, 'folio' => $row->metadata_folio];
            $annulment = $row->metadata_annul_id === null ? null : (object) ['motivo' => $row->metadata_reason, 'creado_en' => $row->metadata_annulled_at];
        } else {
            $snapshot = json_decode($row->datos_emision, true, flags: JSON_THROW_ON_ERROR);
            $annulment = $this->table('anulaciones_documento')->where('id_documento', $row->id)->first();
        }
        $status = $annulment ? 'ANULADO' : ($row->estado === 'PROCESANDO' && $row->bloqueado_hasta !== null && $row->bloqueado_hasta < now()->format('Y-m-d H:i:s.u') ? 'FALLIDO' : $row->estado);

        return ['id' => $row->id, 'projectId' => $row->id_proyecto, 'type' => $snapshot['type'], 'templateId' => $row->id_plantilla,
            'templateVersion' => (int) $row->numero_version_plantilla, 'version' => (int) $row->version + ($annulment ? 1 : 0), 'status' => $status,
            'snapshotHash' => $row->hash_datos_emision, 'fileHash' => $row->sha256_archivo, 'fileSize' => $row->tamano_archivo === null ? null : (int) $row->tamano_archivo,
            'issuedAt' => $row->emitido_en, 'folio' => $snapshot['folio'] ?? null, 'annulmentReason' => $annulment?->motivo, 'annulledAt' => $annulment?->creado_en];
    }

    public function execute(string $operation, ?string $resource, array $input, string $key, string $actor, string $correlation): array
    {
        abort_unless((bool) preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key), 422);

        return DB::transaction(function () use ($operation, $resource, $input, $key, $actor, $correlation): array {
            $this->gate(true);
            $permission = match ($operation) {
                'template-version' => 'templates.manage','issue','retry' => 'documents.issue','annul' => 'documents.revoke',default => abort(404)
            };
            $this->permission($actor, $permission);
            if ($operation === 'issue') {
                [$project,$resident] = $this->project((string) $resource, $actor);
                abort_if($resident->id_identidad === $actor, 403);
            }
            if (in_array($operation, ['retry', 'annul'], true)) {
                $row = $this->find('documentos_emitidos', (string) $resource);
                $this->access($row, $actor);
                $resident = $this->find('residentes', $row->id_residente);
                abort_if($resident->id_identidad === $actor, 403);
                if ($row->tipo_documento === 'CONSTANCIA_ASESORIA') {
                    $ownContributor = $this->table('constancias as c')->join('residencias_proyectos_constancia as link', 'link.id_constancia', '=', 'c.id')
                        ->join('residencias_proyectos as p', 'p.id', '=', 'link.id_proyecto')->join('residencias_residentes as r', 'r.id', '=', 'p.id_residente')
                        ->where('c.id_documento_emitido', $row->id)->where('r.id_identidad', $actor)->exists();
                    abort_if($ownContributor, 403);
                }
            }
            $hash = hash('sha256', $actor.'|document|'.$operation.'|'.$resource.'|'.$key);
            $fingerprint = hash('sha256', DocumentRules::canonical(['op' => $operation, 'id' => $resource, 'input' => $input]));
            $previous = $this->table('claves_idempotencia')->where('clave', $hash)->first();
            if ($previous) {
                abort_unless(hash_equals($previous->hash_solicitud, $fingerprint) && $previous->expira_en > now()->format('Y-m-d H:i:s.u'), 409);

                return json_decode($previous->cuerpo_respuesta, true, flags: JSON_THROW_ON_ERROR);
            }
            $result = match ($operation) {
                'template-version' => $this->version($input, $actor),'issue' => $this->issue($project, $resident, $input, $actor, $correlation),
                'annul' => $this->annul($row, $input, $actor, $correlation),'retry' => $this->retry($row, $input, $actor, $correlation)
            };
            $this->audit(strtoupper(str_replace('-', '_', $operation)), $result['id'], $actor, $correlation);
            $this->table('claves_idempotencia')->insert(['id' => (string) Str::ulid(), 'clave' => $hash, 'hash_solicitud' => $fingerprint,
                'cuerpo_respuesta' => json_encode($result, JSON_THROW_ON_ERROR), 'expira_en' => now()->addDay()]);

            return $result;
        }, 3);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> */
    private function version(array $input, string $actor): array
    {
        abort_unless(in_array($input['type'], DocumentRules::TYPES, true), 422);
        $last = $this->table('plantillas_documento')->where('codigo', $input['type'])->orderByDesc('numero_version')->firstOrFail();
        abort_unless((int) $last->numero_version === (int) $input['expectedPreviousVersion'] && (int) $last->numero_version < 65535, 409);
        $configuration = json_decode($last->configuracion, true, flags: JSON_THROW_ON_ERROR);
        foreach (['departmentSignerId', 'divisionSignerId', 'departmentTitle', 'divisionTitle'] as $field) {
            if (array_key_exists($field, $input)) {
                $configuration[$field] = $input[$field];
            }
        }
        foreach (['departmentSignerId', 'divisionSignerId'] as $field) {
            if ($configuration[$field] ?? null) {
                $this->signer($configuration[$field]);
            }
        }
        if ($input['active'] && ! str_starts_with($input['type'], 'FORMATO_REPORTE_')) {
            abort_unless($configuration['departmentSignerId'] ?? null, 422);
        }
        if ($input['active'] && $input['type'] === 'CONSTANCIA_ASESORIA') {
            abort_unless($configuration['divisionSignerId'] ?? null, 422);
        }
        $configuration['viewHash'] = $this->viewHash($input['type']);
        $configuration['rendererRevision'] = 'legacy-20261028.1';
        $id = (string) Str::ulid();
        $number = (int) $last->numero_version + 1;
        $this->table('plantillas_documento')->where('codigo', $input['type'])->update(['activo' => false, 'actualizado_en' => now()]);
        $this->table('plantillas_documento')->insert(['id' => $id, 'codigo' => $input['type'], 'tipo_documento' => $input['type'], 'nombre' => $input['name'],
            'numero_version' => $number, 'activo' => $input['active'], 'configuracion' => DocumentRules::canonical($configuration), 'creado_en' => now(), 'actualizado_en' => now()]);
        if (array_key_exists('resourceIds', $input)) {
            $resources = [];
            foreach ($input['resourceIds'] as $prepared) {
                $row = $this->find('recursos_preparados', $prepared);
                abort_unless($row->id_identidad_carga === $actor, 403);
                abort_unless($row->estado === 'LISTO' && $row->utilizado_en === null && $row->expira_en > now()->format('Y-m-d H:i:s.u'), 409);
                $metadata = $this->files->metadata($row->id_archivo);
                abort_unless($metadata && $metadata['ownerId'] === $actor && $metadata['sha256'] === $row->sha256 && $metadata['size'] === (int) $row->tamano_bytes && $metadata['mime'] === $row->tipo_mime, 503);
                $this->asset($row->id_archivo, null);
                $resources[] = ['id_archivo' => $row->id_archivo, 'clave_integrada' => null];
                $this->table('recursos_preparados')->where('id', $row->id)->update(['utilizado_en' => now()]);
            }
        } else {
            $resources = $this->table('recursos_plantilla')->where('id_plantilla', $last->id)->orderBy('orden_visualizacion')->get(['id_archivo', 'clave_integrada'])->map(fn (object $r): array => (array) $r)->all();
            if ($resources === []) {
                $resources = array_map(fn (string $key): array => ['id_archivo' => null, 'clave_integrada' => $key], DocumentRules::logos($input['type']));
            }
        }
        foreach ($resources as $index => $resource) {
            $this->table('recursos_plantilla')->insert($resource + ['id' => (string) Str::ulid(), 'id_plantilla' => $id, 'rol_recurso' => 'LOGO',
                'orden_visualizacion' => $index, 'activo' => true]);
        }

        return $this->template($this->find('plantillas_documento', $id));
    }

    private function viewHash(string $type): string
    {
        return hash_file('sha256', resource_path('views/residencies/documents/'.DocumentRules::VIEWS[$type].'.blade.php'));
    }

    /**
     * @return array<string,mixed> */
    private function signer(string $id, bool $active = true): array
    {
        $employee = $this->organization->employee($id);
        abort_unless(! $active || $employee['status'] === 'ACTIVO', 409);
        $positions = array_values(array_filter($employee['positions'], fn (array $r): bool => $r['active']));
        $microsoft = array_values(array_filter($positions, fn (array $r): bool => $r['source'] === 'MICROSOFT'));
        $degree = $employee['degrees'][0]['abbreviation'] ?? '';

        return ['id' => $id, 'name' => trim($degree.' '.$employee['givenNames'].' '.$employee['firstSurname'].' '.($employee['secondSurname'] ?? '')),
            'position' => $microsoft[0]['name'] ?? $positions[0]['name'] ?? null];
    }

    /**
     * @return array<string,mixed> */
    private function asset(?string $file, ?string $builtin): array
    {
        if ($builtin !== null) {
            $allowed = array_unique(array_merge(...array_map(fn (string $type): array => DocumentRules::logos($type), DocumentRules::TYPES)));
            abort_unless(in_array($builtin, $allowed, true), 422);
            $path = resource_path('documents/logos/'.$builtin);
            abort_unless(is_file($path), 503);
            $bytes = file_get_contents($path);
            $mime = 'image/png';
            $source = ['builtin' => $builtin];
        } else {
            $metadata = $this->files->metadata((string) $file);
            abort_unless($metadata && $metadata['status'] === 'LIMPIO', 503);
            $content = $this->files->bytes((string) $file);
            $bytes = $content['content'];
            $mime = $content['mime'];
            $source = ['fileId' => $file];
        }
        abort_unless(is_string($bytes) && strlen($bytes) <= 524288 && in_array($mime, ['image/png', 'image/jpeg'], true), 422);
        $dimensions = @getimagesizefromstring($bytes);
        abort_unless($dimensions && $dimensions[0] <= $this->maxDimension() && $dimensions[1] <= $this->maxDimension() && $dimensions[0] * $dimensions[1] <= 2000000, 422);

        return $source + ['mime' => $mime, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'dataUri' => 'data:'.$mime.';base64,'.base64_encode($bytes)];
    }

    private function maxDimension(): int
    {
        return 2000;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> */
    private function issue(object $project, object $resident, array $input, string $actor, string $correlation): array
    {
        abort_unless($resident->estado === 'ACTIVO' && ! in_array($project->estado, ['CANCELADO', 'ARCHIVADO'], true), 409);
        $template = $this->find('plantillas_documento', $input['templateId']);
        $type = $input['type'];
        abort_unless($template->activo && $template->tipo_documento === $type && (int) $template->numero_version === (int) $input['expectedTemplateVersion'], 409);
        $configuration = json_decode($template->configuracion, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($configuration['viewHash'] ?? null) === $this->viewHash($type), 409);
        $department = ($configuration['departmentSignerId'] ?? null) ? $this->signer($configuration['departmentSignerId']) : null;
        $division = ($configuration['divisionSignerId'] ?? null) ? $this->signer($configuration['divisionSignerId']) : null;
        $program = $this->find('programas_educativos', $project->id_programa);
        $company = $this->find('empresas', $project->id_empresa);
        $identity = $this->identities->find($resident->id_identidad);
        $now = now('America/Mexico_City');
        $external = $this->table('asesores_externos_proyecto')->where('id_proyecto', $project->id)->where('codigo_rol', 'PRINCIPAL')->orderBy('fecha_inicio')->first();
        if ($type === 'CARTA_PRESENTACION_FORMAL') {
            abort_unless($external !== null, 409);
        }
        $recipient = null;
        if (isset($input['recipientContactId'])) {
            $recipient = $this->find('contactos_empresa', $input['recipientContactId']);
            abort_unless($recipient->id_empresa === $company->id && $recipient->activo, 422);
        }
        $fields = ['dia' => $now->format('d'), 'mes' => DocumentRules::month($now->month), 'anio' => $now->format('Y'),
            'nombre_jefe_depto' => $department['name'] ?? '', 'jefe_depto' => $configuration['departmentTitle'],
            'nombre_residente' => trim($resident->nombres.' '.$resident->primer_apellido.' '.($resident->segundo_apellido ?? '')),
            'nombre_estudiante' => trim($resident->nombres.' '.$resident->primer_apellido.' '.($resident->segundo_apellido ?? '')),
            'numero_control' => $resident->numero_control, 'programa_educativo' => $program->nombre, 'carrera' => $program->nombre,
            'nombre_proyecto' => $project->nombre, 'tipo_proyecto' => match ($project->procedencia) {
                'BANCO_PROYECTOS' => 'Banco de Proyectos','PROPUESTA_PROPIA' => 'Propuesta Propia',default => ''
            },
            'periodo' => $project->fecha_inicio.' - '.$project->fecha_fin, 'numero_residentes' => (int) $project->numero_vacantes,
            'nombre_empresa' => $company->razon_social, 'rfc' => $company->rfc, 'ramo' => 'Industrial ( ) Servicios ( ) Otro ( )', 'sector' => 'Publico ( ) Privado ( )',
            'colonia_empresa' => $company->domicilio ?? '', 'cp_empresa' => $company->codigo_postal ?? '', 'fax' => $company->fax ?? '', 'ciudad_empresa' => $company->ciudad ?? '',
            'telefono_empresa' => $company->telefono ?? '', 'celular_empresa' => $company->telefono_celular ?? '', 'mision' => $company->mision ?? '',
            'nombre_titular' => $recipient ? trim(($recipient->nombre_grado ?? '').' '.$recipient->nombres.' '.$recipient->primer_apellido.' '.($recipient->segundo_apellido ?? '')) : '',
            'puesto_titular' => $recipient->nombre_puesto ?? '', 'nombre_asesor' => $external->nombre_instantanea ?? '', 'puesto_asesor' => $external->puesto_instantanea ?? '',
            'asesor_externo' => $external->nombre_instantanea ?? '', 'puesto_asesor_externo' => $external->puesto_instantanea ?? '',
            'domicilio_estudiante' => $resident->domicilio ?? '', 'principal_name' => $identity['email'] ?? $resident->correo_institucional ?? '',
            'tipo_seguro' => $resident->tipo_seguro ?? '', 'no_seguro' => $resident->numero_seguro_social ?? '', 'ciudad_estudiante' => $resident->ciudad ?? '',
            'telefono_estudiante' => $resident->telefono ?? '', 'celular_estudiante' => $resident->telefono_celular ?? ''];
        if ($company->id_ramo) {
            $name = $this->find('ramos', $company->id_ramo)->nombre;
            $fields['ramo'] = $name === 'Servicios' ? 'Industrial ( ) Servicios (X) Otro ( )' : ($name === 'Industrial' ? 'Industrial (X) Servicios ( ) Otro ( )' : 'Industrial ( ) Servicios ( ) Otro (X)');
        }
        if ($company->id_sector) {
            $name = $this->find('sectores', $company->id_sector)->nombre;
            $fields['sector'] = $name === 'Privado' ? 'Publico ( ) Privado (X)' : 'Publico (X) Privado ( )';
        }
        $report = null;
        if (str_starts_with($type, 'FORMATO_REPORTE_')) {
            $report = $this->table('reportes_avance')->where('id_proyecto', $project->id)->where('tipo_reporte', $type === 'FORMATO_REPORTE_FINAL' ? 'FINAL' : 'PARCIAL')->where('numero_consecutivo', $input['reportSequence'] ?? 1)->first();
            abort_unless($report !== null, 409);
            $fields['fecha_periodo'] = $report->fecha_inicio.' - '.$report->fecha_fin;
            $fields['numero_reporte'] = (int) $report->numero_consecutivo;
        }
        $resources = $this->table('recursos_plantilla')->where('id_plantilla', $template->id)->where('activo', true)->orderBy('orden_visualizacion')->get();
        $assets = $resources->map(fn (object $r): array => $this->asset($r->id_archivo, $r->clave_integrada))->all();
        $certificate = $type === 'CONSTANCIA_ASESORIA' ? $this->certificate($project, $input, $department, $division, $configuration, $actor) : null;
        $id = (string) Str::ulid();
        $snapshot = ['schemaVersion' => 1, 'documentId' => $id, 'type' => $type, 'issuedAt' => $now->toISOString(), 'issuerId' => $actor,
            'residentId' => $resident->id, 'projectId' => $project->id, 'projectVersion' => (int) $project->version, 'template' => ['id' => $template->id, 'version' => (int) $template->numero_version,
                'configuration' => $configuration, 'renderer' => InstalledVersions::getPrettyVersion('dompdf/dompdf')],
            'fields' => $fields, 'assets' => $assets, 'signers' => ['department' => $department, 'division' => $division], 'folio' => $certificate['folio'] ?? null,
            'certificateFields' => $certificate['fields'] ?? null, 'contributingProjects' => $certificate['projects'] ?? []];
        $canonical = DocumentRules::canonical($snapshot);
        $html = $this->renderer->html($snapshot);
        $this->table('documentos_emitidos')->insert(['id' => $id, 'id_residente' => $resident->id, 'id_proyecto' => $project->id, 'id_plantilla' => $template->id,
            'tipo_documento' => str_starts_with($type, 'FORMATO_REPORTE_') ? 'OTRO' : $type, 'numero_version_plantilla' => $template->numero_version,
            'datos_emision' => $canonical, 'hash_datos_emision' => hash('sha256', $canonical), 'html_emision' => $html, 'hash_html' => hash('sha256', $html),
            'id_identidad_emisor' => $actor, 'emitido_en' => $now->copy()->utc(), 'estado' => 'PENDIENTE', 'version' => 1]);
        if ($certificate) {
            $certificateId = (string) Str::ulid();
            $this->table('constancias')->insert(['id' => $certificateId, 'id_proyecto' => $project->id,
                'id_asignacion_asesor_interno' => $input['adviserAssignmentId'], 'anio' => $input['year'], 'numero_periodo' => $input['semester'], 'numero_consecutivo' => $certificate['number'],
                'folio' => $certificate['folio'], 'rango_inicio' => $input['startsOn'], 'rango_fin' => $input['endsOn'], 'id_identidad_generador' => $actor, 'id_documento_emitido' => $id, 'generado_en' => now()]);
            foreach ($certificate['projects'] as $contributor) {
                $this->table('proyectos_constancia')->insert(['id' => (string) Str::ulid(), 'id_constancia' => $certificateId, 'id_proyecto' => $contributor]);
            }
        }
        $this->enqueue($id, $actor, $correlation);

        return $this->document($this->find('documentos_emitidos', $id));
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $department
     * @param array<string,mixed>|null $division
     * @param array<string,mixed> $configuration
     * @return array<string,mixed> */
    private function certificate(object $project, array $input, ?array $department, ?array $division, array $configuration, string $actor): array
    {
        abort_unless($department && $division && $project->estado === 'COMPLETADO', 409);
        $assignment = $this->find('asesores_internos_proyecto', $input['adviserAssignmentId']);
        abort_unless($assignment->id_proyecto === $project->id, 422);
        [$from,$until] = DocumentRules::semester((int) $input['year'], (int) $input['semester']);
        ResidencyRules::contained($input['startsOn'], $input['endsOn'], $from, $until);
        abort_if((int) $input['year'] > now('America/Mexico_City')->year, 422);
        $rows = $this->table('proyectos as p')->join('residencias_asesores_internos_proyecto as a', 'a.id_proyecto', '=', 'p.id')
            ->join('residencias_liberaciones as l', 'l.id_proyecto', '=', 'p.id')->join('residencias_residentes as r', 'r.id', '=', 'p.id_residente')
            ->where('a.id_empleado', $assignment->id_empleado)->where('p.id_programa', $project->id_programa)->where('p.estado', 'COMPLETADO')->where('l.estado', 'APROBADO')
            ->whereBetween('p.fecha_fin', [$from, $until])->where('p.fecha_inicio', '<=', $input['endsOn'])->where('p.fecha_fin', '>=', $input['startsOn'])
            ->where('a.fecha_inicio', '<=', $input['endsOn'])->whereRaw('COALESCE(a.fecha_fin,p.fecha_fin)>=?', [$input['startsOn']])
            ->orderBy('r.primer_apellido')->orderBy('p.id')->orderBy('a.fecha_inicio')->limit(501)->get(['p.id', 'p.nombre', 'p.fecha_inicio', 'p.fecha_fin', 'r.id_identidad', 'r.nombres', 'r.primer_apellido', 'r.segundo_apellido', 'a.fecha_inicio as asesor_inicio', 'a.fecha_fin as asesor_fin']);
        $groups = $rows->groupBy('id');
        abort_unless($rows->isNotEmpty() && $rows->count() <= 500 && $groups->count() <= 100 && $groups->has($project->id), 409);
        abort_if($rows->contains(fn (object $row): bool => $row->id_identidad === $actor), 403);
        $advisor = $this->signer($assignment->id_empleado, false);
        $program = $this->find('programas_educativos', $project->id_programa);
        $year = (int) $input['year'];
        $semester = (int) $input['semester'];
        $allocator = $this->table('secuencias_folio')->where('anio', $year)->where('numero_periodo', $semester)->first();
        $number = (int) ($allocator->ultimo_numero ?? 0) + 1;
        abort_unless($number <= 4294967295, 409);
        if ($allocator) {
            $this->table('secuencias_folio')->where('id', $allocator->id)->update(['ultimo_numero' => $number]);
        } else {
            $this->table('secuencias_folio')->insert(['id' => (string) Str::ulid(), 'anio' => $year, 'numero_periodo' => $semester, 'ultimo_numero' => $number]);
        }
        $folio = DocumentRules::folio($year, $semester, $number);
        $date = now('America/Mexico_City');
        $names = $groups->map(function ($intervals) use ($input, $program): array {
            $r = $intervals->first();
            $ranges = $intervals->map(function (object $interval) use ($input): string {
                $start = max($interval->fecha_inicio, $interval->asesor_inicio, $input['startsOn']);
                $end = min($interval->fecha_fin, $interval->asesor_fin ?? $interval->fecha_fin, $input['endsOn']);

                return $start.' - '.$end;
            })->unique()->implode('; ');

            return ['alumno' => mb_strtoupper(trim($r->nombres.' '.$r->primer_apellido.' '.($r->segundo_apellido ?? '')), 'UTF-8'), 'proyecto' => mb_strtoupper($r->nombre, 'UTF-8'),
                'periodo' => $ranges, 'carrera' => mb_strtoupper($program->nombre, 'UTF-8')];
        })->values()->all();

        return ['folio' => $folio, 'number' => $number, 'projects' => $groups->keys()->all(), 'fields' => [
            'tecnm' => 'EL TECNOLÓGICO NACIONAL DE MÉXICO', 'instituto' => $configuration['institution'], 'clave' => $configuration['institutionKey'],
            'asesor_nombre' => mb_strtoupper($advisor['name'], 'UTF-8'), 'periodo_texto' => ($semester === 1 ? 'FEBRERO - JULIO ' : 'AGOSTO - DICIEMBRE ').$year, 'filas' => $names,
            'lugar_fecha' => mb_strtoupper($configuration['city'].', a '.$date->day.' de '.DocumentRules::month($date->month).' del '.$date->year.'.', 'UTF-8'),
            'jefe_dpto_nombre' => mb_strtoupper($department['name'], 'UTF-8'), 'jefe_dpto_cargo' => mb_strtoupper($configuration['departmentTitle'], 'UTF-8'),
            'jefe_div_nombre' => mb_strtoupper($division['name'], 'UTF-8'), 'jefe_div_cargo' => mb_strtoupper($configuration['divisionTitle'], 'UTF-8'), 'folio' => $folio]];
    }

    private function enqueue(string $id, string $actor, string $correlation): void
    {
        $event = (string) Str::ulid();
        $type = 'residencies.document-requested.v1';
        DB::table('compartido_mensajes_salida')->insert(['id' => $event, 'nombre_contexto' => 'Residencies', 'tipo_agregado' => 'IssuedDocument', 'id_agregado' => $id, 'tipo_evento' => $type,
            'contenido' => json_encode(['eventId' => $event, 'type' => $type, 'version' => 1, 'aggregateId' => $id, 'actor' => $actor, 'correlationId' => $correlation, 'occurredAt' => now()->toISOString(),
                'data' => ['documentId' => $id]], JSON_THROW_ON_ERROR), 'estado' => 'PENDING', 'ocurrido_en' => now(), 'disponible_en' => now()]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> */
    private function annul(object $row, array $input, string $actor, string $correlation): array
    {
        abort_unless((int) $row->version === (int) $input['expectedVersion'] && ! $this->table('anulaciones_documento')->where('id_documento', $row->id)->exists(), 409);
        $this->annulRecord($row->id, ProgressRules::reason($input['observations']), 'MANUAL', $actor, $correlation);

        return $this->document($this->find('documentos_emitidos', $row->id));
    }

    private function annulRecord(string $id, string $reason, string $source, string $actor, string $correlation): void
    {
        if ($this->table('anulaciones_documento')->where('id_documento', $id)->exists()) {
            return;
        }
        $this->table('anulaciones_documento')->insert(['id' => (string) Str::ulid(), 'id_documento' => $id, 'motivo' => $reason, 'origen' => $source, 'id_identidad_actor' => $actor, 'creado_en' => now()]);
        $this->audit('DOCUMENT_ANNULLED', $id, $actor, $correlation);
    }

    public function invalidateForProject(string $project, string $reason, string $actor, string $correlation): void
    {
        $ids = $this->table('constancias as c')->join('residencias_proyectos_constancia as p', 'p.id_constancia', '=', 'c.id')->where('p.id_proyecto', $project)->pluck('c.id_documento_emitido');
        foreach ($ids as $id) {
            $this->annulRecord($id, $reason, 'RELEASE_REOPENED', $actor, $correlation);
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> */
    private function retry(object $row, array $input, string $actor, string $correlation): array
    {
        abort_unless((int) $row->version === (int) $input['expectedVersion'] && ! $this->table('anulaciones_documento')->where('id_documento', $row->id)->exists(), 409);
        abort_unless($row->estado === 'FALLIDO' || ($row->estado === 'PROCESANDO' && $row->bloqueado_hasta < now()->format('Y-m-d H:i:s.u')), 409);
        $this->snapshotIntegrity($row);
        $this->table('documentos_emitidos')->where('id', $row->id)->update(['estado' => 'PENDIENTE', 'bloqueado_por' => null, 'bloqueado_hasta' => null, 'ultimo_error' => null, 'version' => (int) $row->version + 1]);
        $this->enqueue($row->id, $actor, $correlation);

        return $this->document($this->find('documentos_emitidos', $row->id));
    }

    private function snapshotIntegrity(object $row): void
    {
        $snapshot = json_decode($row->datos_emision, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(hash_equals($row->hash_datos_emision, hash('sha256', DocumentRules::canonical($snapshot))) && hash_equals($row->hash_html, hash('sha256', $row->html_emision)), 503);
    }

    /** @return array{lease:string,html:string,actor:string}|null */
    private function claimDocument(string $id): ?array
    {
        $this->gate(true);
        $row = $this->find('documentos_emitidos', $id);
        if ($row->estado === 'LISTO' || $this->table('anulaciones_documento')->where('id_documento', $id)->exists() || ($row->estado === 'PROCESANDO' && $row->bloqueado_hasta > now()->format('Y-m-d H:i:s.u'))) {
            return null;
        }
        try {
            $this->permission($row->id_identidad_emisor, 'documents.issue');
            $this->snapshotIntegrity($row);
        } catch (Throwable) {
            $this->table('documentos_emitidos')->where('id', $id)->update(['estado' => 'FALLIDO', 'bloqueado_por' => null, 'bloqueado_hasta' => null, 'ultimo_error' => 'RENDER_PRECONDITION_FAILED', 'version' => (int) $row->version + 1]);
            $this->audit('DOCUMENT_RENDER_REJECTED', $id, $row->id_identidad_emisor, (string) Str::uuid());

            return null;
        }
        $lease = (string) Str::uuid();
        $this->table('documentos_emitidos')->where('id', $id)->update(['estado' => 'PROCESANDO', 'bloqueado_por' => $lease, 'bloqueado_hasta' => now()->addSeconds(180), 'version' => (int) $row->version + 1]);

        return ['lease' => $lease, 'html' => $row->html_emision, 'actor' => $row->id_identidad_emisor];
    }

    public function render(string $id): void
    {
        $claim = DB::transaction(fn (): ?array => $this->claimDocument($id), 3);
        if ($claim === null) {
            return;
        }$path = null;
        try {
            $bytes = $this->renderer->pdf($claim['html']);
            $path = tempnam(storage_path('framework/cache'), 'residency-pdf-');
            if ($path === false || file_put_contents($path, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('RENDER_TEMP_UNAVAILABLE');
            }
            $file = $this->files->store($path, $claim['actor']);
            $metadata = $this->files->metadata($file);
            if (! $metadata || $metadata['status'] !== 'LIMPIO' || $metadata['ownerId'] !== $claim['actor'] || $metadata['size'] !== strlen($bytes) || $metadata['mime'] !== 'application/pdf' || ! hash_equals($metadata['sha256'], hash('sha256', $bytes))) {
                throw new RuntimeException('RENDER_SCAN_REJECTED');
            }
            DB::transaction(function () use ($id, $claim, $file, $metadata): void {
                $this->gate(true);
                $row = $this->find('documentos_emitidos', $id);
                abort_unless($row->estado === 'PROCESANDO' && $row->bloqueado_por === $claim['lease'] && ! $this->table('anulaciones_documento')->where('id_documento', $id)->exists(), 409);
                $this->permission($claim['actor'], 'documents.issue');
                $this->snapshotIntegrity($row);
                $this->table('documentos_emitidos')->where('id', $id)->update(['id_archivo' => $file, 'sha256_archivo' => $metadata['sha256'], 'tamano_archivo' => $metadata['size'],
                    'estado' => 'LISTO', 'bloqueado_por' => null, 'bloqueado_hasta' => null, 'ultimo_error' => null, 'version' => (int) $row->version + 1]);
                $this->audit('DOCUMENT_READY', $id, $claim['actor'], (string) Str::uuid());
            }, 3);
        } catch (Throwable) {
            DB::transaction(function () use ($id, $claim): void {
                $this->gate(true);
                $row = $this->find('documentos_emitidos', $id);
                if ($row->estado === 'PROCESANDO' && $row->bloqueado_por === $claim['lease']) {
                    $this->table('documentos_emitidos')->where('id', $id)->update(['estado' => 'FALLIDO', 'bloqueado_por' => null, 'bloqueado_hasta' => null, 'ultimo_error' => 'RENDER_FAILED', 'version' => (int) $row->version + 1]);
                }
            });
            throw new RuntimeException('DOCUMENT_RENDER_FAILED');
        } finally {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }

    public function download(string $id, string $actor): array
    {
        return DB::transaction(function () use ($id, $actor): array {
            $this->gate(false);
            $row = $this->find('documentos_emitidos', $id);
            $this->access($row, $actor);
            abort_unless($row->estado === 'LISTO' && ! $this->table('anulaciones_documento')->where('id_documento', $id)->exists(), 409);
            $this->snapshotIntegrity($row);
            try {
                $metadata = $this->files->metadata($row->id_archivo);
                $bytes = $this->files->bytes($row->id_archivo);
                abort_unless($metadata && $metadata['status'] === 'LIMPIO' && $metadata['ownerId'] === $row->id_identidad_emisor && $metadata['mime'] === 'application/pdf'
                    && $metadata['sha256'] === $row->sha256_archivo && $metadata['size'] === (int) $row->tamano_archivo && hash_equals(hash('sha256', $bytes['content']), $row->sha256_archivo), 503);
            } catch (Throwable) {
                abort(503);
            }
            $this->audit('DOCUMENT_DOWNLOADED', $id, $actor, (string) Str::uuid());

            return ['content' => $bytes['content'], 'mime' => 'application/pdf', 'name' => 'document-'.$id.'.pdf'];
        });
    }

    public function stageResource(string $path, string $key, string $actor, string $correlation): array
    {
        $this->permission($actor, 'templates.manage');
        abort_unless(is_file($path) && filesize($path) > 0 && filesize($path) <= 524288 && (bool) preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key), 422);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $dimensions = @getimagesize($path);
        abort_unless(in_array($mime, ['image/png', 'image/jpeg'], true) && $dimensions && $dimensions[0] <= 2000 && $dimensions[1] <= 2000 && $dimensions[0] * $dimensions[1] <= 2000000, 422);
        $size = filesize($path);
        $digest = hash_file('sha256', $path);
        $hash = hash('sha256', $actor.'|template-resource|'.$key);
        $fingerprint = hash('sha256', $mime.'|'.$size.'|'.$digest);
        $slot = DB::transaction(function () use ($hash, $fingerprint, $actor, $mime, $size, $digest): array {
            $this->gate(true);
            $this->permission($actor, 'templates.manage');
            $old = $this->table('recursos_preparados')->where('clave', $hash)->first();
            if ($old) {
                abort_unless(hash_equals($old->hash_solicitud, $fingerprint) && $old->estado === 'LISTO' && $old->expira_en > now()->format('Y-m-d H:i:s.u'), 409);

                return ['id' => $old->id, 'existing' => true];
            }
            abort_if($this->table('recursos_preparados')->where('id_identidad_carga', $actor)->where('creado_en', '>', now()->subDay())->count() >= 100, 429);
            $id = (string) Str::ulid();
            $this->table('recursos_preparados')->insert(['id' => $id, 'clave' => $hash, 'hash_solicitud' => $fingerprint, 'id_identidad_carga' => $actor,
                'estado' => 'PENDIENTE', 'tipo_mime' => $mime, 'tamano_bytes' => $size, 'sha256' => $digest, 'expira_en' => now()->addDay(), 'creado_en' => now()]);

            return ['id' => $id, 'existing' => false];
        }, 3);
        if (! $slot['existing']) {
            $file = null;
            $error = null;
            try {
                $file = $this->files->store($path, $actor);
                $metadata = $this->files->metadata($file);
                if (! $metadata || $metadata['status'] !== 'LIMPIO' || $metadata['sha256'] !== $digest) {
                    $error = 422;
                }
            } catch (Throwable) {
                $error = 503;
            }
            $error = DB::transaction(function () use ($slot, $file, $error, $actor, $correlation): ?int {
                $this->gate(true);
                if (! $this->authorization->allows($actor, 'residencies.templates.manage')) {
                    $error = 403;
                }
                $this->table('recursos_preparados')->where('id', $slot['id'])->update(['id_archivo' => $file, 'estado' => $error === null ? 'LISTO' : 'FALLIDO']);
                $this->audit($error === null ? 'TEMPLATE_RESOURCE_STAGED' : 'TEMPLATE_RESOURCE_FAILED', $slot['id'], $actor, $correlation);

                return $error;
            }, 3);
            abort_if($error !== null, $error ?? 503);
        }
        $row = $this->find('recursos_preparados', $slot['id']);

        return ['id' => $row->id, 'mime' => $row->tipo_mime, 'size' => (int) $row->tamano_bytes, 'sha256' => $row->sha256, 'expiresAt' => $row->expira_en];
    }
}
