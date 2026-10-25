<?php

namespace App\Modules\Residencies\Infrastructure\Persistence;

use App\Modules\IAM\Application\AuthorizationDirectory;
use App\Modules\Residencies\Application\EvidenceWorkflow;
use App\Modules\Residencies\Domain\Services\EvidenceRules;
use App\Modules\Residencies\Domain\Services\ResidencyRules;
use App\Modules\Shared\Application\Ports\ScannedFiles;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class EvidenceStore implements EvidenceWorkflow
{
    public function __construct(private AuthorizationDirectory $authorization, private ScannedFiles $files) {}

    private function table(string $name): Builder
    {
        return DB::table('residencias_'.$name);
    }

    private function find(string $name, string $id): object
    {
        $row = $this->table($name)->where('id', $id)->first();
        abort_unless($row !== null, 404);

        return $row;
    }

    private function gate(bool $exclusive): void
    {
        $query = $this->table('controles')->where('codigo', 'WRITER');
        ($exclusive ? $query->lockForUpdate() : $query->sharedLock())->firstOrFail();
    }

    private function permission(string $actor, string $permission, ?string $owner = null): void
    {
        abort_unless($this->authorization->allows($actor, 'residencies.'.$permission, $owner), 403);
    }

    private function resident(string $id, ?string $project, string $actor, string $permission = 'files.read', bool $write = false): object
    {
        $resident = $this->find('residentes', $id);
        $this->permission($actor, $permission, $resident->id_identidad);
        if ($write) {
            abort_unless($resident->estado === 'ACTIVO', 409);
        }
        if ($project !== null) {
            $row = $this->find('proyectos', $project);
            abort_unless($row->id_residente === $id, 404);
            if ($write) {
                abort_unless($row->estado === 'BORRADOR' && $this->find('periodos', $row->id_periodo)->estado === 'ACTIVO', 409);
            }
        }

        return $resident;
    }

    /** @param array<string, mixed> $input */
    private function applicable(array $input, string $actor): object
    {
        $resident = $this->resident($input['residentId'], $input['projectId'] ?? null, $actor, 'evidence.submit', true);
        $definition = $this->find('definiciones_requisito', $input['requirementId']);
        abort_unless($definition->activo && in_array($definition->etapa, ['PRERREQUISITO', 'REQUISITO'], true)
            && ($definition->id_programa === null || $definition->id_programa === $resident->id_programa)
            && ($definition->alcance === 'PROYECTO') === (($input['projectId'] ?? null) !== null)
            && EvidenceRules::inForce($definition->vigente_desde, $definition->vigente_hasta, Carbon::now('America/Mexico_City')->toDateString()), 409);

        return $definition;
    }

    private function reviewer(object $evidence, string $actor): void
    {
        $this->permission($actor, 'evidence.review');
        $resident = $this->resident($evidence->id_residente, $evidence->id_proyecto, $actor);
        abort_if($resident->id_identidad === $actor || $evidence->id_identidad_envio === $actor, 403);
    }

    private function audit(string $action, string $kind, string $id, string $actor, string $correlation): void
    {
        DB::table('compartido_registros_auditoria')->insert(['id' => (string) Str::ulid(), 'ocurrido_en' => now(),
            'id_identidad_actor' => $actor, 'nombre_contexto' => 'Residencies', 'accion' => $action,
            'tipo_sujeto' => $kind, 'id_sujeto' => $id, 'id_correlacion' => $correlation]);
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed> */
    public function read(string $operation, ?string $resource, array $filters, string $actor): array
    {
        return DB::transaction(function () use ($operation, $resource, $filters, $actor): array {
            $this->gate(false);
            if ($operation === 'definitions') {
                $this->permission($actor, 'requirements.manage');
                if ($resource !== null) {
                    return $this->definition($this->find('definiciones_requisito', $resource));
                }
                $query = $this->table('definiciones_requisito');
                if (($filters['q'] ?? '') !== '') {
                    $pattern = '%'.strtr($filters['q'], ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
                    $query->whereRaw("nombre LIKE ? ESCAPE '!'", [$pattern]);
                }
                [$rows, $cursor] = $this->page($query, $operation, $resource, $filters, $actor);

                return ['items' => array_map(fn (object $row): array => $this->definition($row), $rows), 'nextCursor' => $cursor];
            }
            $reviewAllowed = $this->authorization->allows($actor, 'residencies.evidence.review');
            if ($operation === 'requirements') {
                $project = $filters['projectId'] ?? null;
                $resident = $this->resident((string) $resource, $project, $actor);
                $query = $this->table('definiciones_requisito')->where('alcance', $project === null ? 'RESIDENTE' : 'PROYECTO')
                    ->where(fn (Builder $q) => $q->whereNull('id_programa')->orWhere('id_programa', $resident->id_programa));
                if (isset($filters['requirementId'])) {
                    $query->where('id', $filters['requirementId']);
                }
                [$rows, $cursor] = $this->page($query, $operation, $resource, $filters, $actor);
                $ids = array_map(fn (object $row): string => $row->id, $rows);
                $lastVersions = $this->scope((string) $resource, $project)->whereIn('id_requisito', $ids)->select('id_requisito')
                    ->selectRaw('MAX(numero_version) AS last_version')->groupBy('id_requisito');
                $latest = $this->table('entregas_evidencia as e')->joinSub($lastVersions, 'current_versions', function (JoinClause $join): void {
                    $join->on('e.id_requisito', '=', 'current_versions.id_requisito')->on('e.numero_version', '=', 'current_versions.last_version');
                })->where('e.id_residente', $resource)->where('e.id_proyecto', $project)->select('e.*')->get()->keyBy('id_requisito');
                $canSubmit = $this->authorization->allows($actor, 'residencies.evidence.submit', $resident->id_identidad);
                $projectWritable = true;
                if ($project !== null) {
                    $parent = $this->find('proyectos', $project);
                    $projectWritable = $parent->estado === 'BORRADOR' && $this->find('periodos', $parent->id_periodo)->estado === 'ACTIVO';
                }
                $items = [];
                foreach ($rows as $row) {
                    $evidence = $latest->get($row->id);
                    $definition = $this->definition($row);
                    $definition['latestEvidence'] = $evidence ? $this->evidence($evidence, $actor, $resident->id_identidad, $reviewAllowed) : null;
                    $definition['canSubmit'] = $canSubmit && $projectWritable
                        && $resident->estado === 'ACTIVO' && (bool) $row->activo
                        && EvidenceRules::inForce($row->vigente_desde, $row->vigente_hasta, Carbon::now('America/Mexico_City')->toDateString())
                        && ($evidence === null || EvidenceRules::replaceable($evidence->estado));
                    $items[] = $definition;
                }

                return ['items' => $items, 'nextCursor' => $cursor];
            }
            if ($operation === 'queue') {
                $this->permission($actor, 'evidence.review');
                $query = $this->table('entregas_evidencia')->where('estado', 'ENVIADO')->where('id_identidad_envio', '!=', $actor)
                    ->whereIn('id_residente', $this->table('residentes')->where('id_identidad', '!=', $actor)->select('id'));
                if (isset($filters['residentId'])) {
                    $query->where('id_residente', $filters['residentId']);
                }
                [$rows, $cursor] = $this->page($query, $operation, $resource, $filters, $actor);
                $owners = $this->table('residentes')->whereIn('id', array_map(fn (object $row): string => $row->id_residente, $rows))->pluck('id_identidad', 'id');

                return ['items' => array_map(fn (object $row): array => $this->evidence($row, $actor, $owners->get($row->id_residente), $reviewAllowed), $rows), 'nextCursor' => $cursor];
            }
            $evidence = $this->find('entregas_evidencia', (string) $resource);
            $resident = $this->resident($evidence->id_residente, $evidence->id_proyecto, $actor);
            if ($operation === 'evidence') {
                return $this->evidence($evidence, $actor, $resident->id_identidad, $reviewAllowed);
            }
            abort_unless($operation === 'history', 404);
            $query = $this->scope($evidence->id_residente, $evidence->id_proyecto)->where('id_requisito', $evidence->id_requisito);
            [$rows, $cursor] = $this->page($query, $operation, $evidence->id_requisito.'|'.$evidence->id_alcance, $filters, $actor);
            $reviews = $this->table('revisiones_evidencia')->whereIn('id_entrega', array_map(fn (object $row): string => $row->id, $rows))
                ->orderBy('numero_revision')->get()->groupBy('id_entrega');

            return ['items' => array_map(function (object $row) use ($actor, $resident, $reviews, $reviewAllowed): array {
                $item = $this->evidence($row, $actor, $resident->id_identidad, $reviewAllowed);
                $item['reviews'] = $reviews->get($row->id, collect())->map(fn (object $review): array => $this->review($review))->all();

                return $item;
            }, $rows), 'nextCursor' => $cursor];
        });
    }

    private function scope(string $resident, ?string $project): Builder
    {
        return $this->table('entregas_evidencia')->where('id_residente', $resident)
            ->when($project !== null, fn (Builder $q) => $q->where('id_proyecto', $project), fn (Builder $q) => $q->whereNull('id_proyecto'));
    }

    /** @param array<string, mixed> $filters
     * @return array{list<object>, ?string} */
    private function page(Builder $query, string $operation, ?string $resource, array $filters, string $actor): array
    {
        $ordered = in_array($operation, ['definitions', 'requirements'], true);
        $size = (int) ($filters['pageSize'] ?? 25);
        abort_unless($size >= 1 && $size <= 100, 422);
        $binding = hash('sha256', json_encode([$operation, $resource, $actor, $size, $filters['projectId'] ?? null, $filters['residentId'] ?? null, $filters['requirementId'] ?? null, $filters['q'] ?? ''], JSON_THROW_ON_ERROR));
        if (isset($filters['cursor'])) {
            try {
                $cursor = json_decode(Crypt::decryptString($filters['cursor']), true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                abort(422);
            }
            abort_unless(is_array($cursor) && ($cursor['binding'] ?? '') === $binding && Str::isUlid($cursor['id'] ?? ''), 422);
            if ($ordered) {
                abort_unless(is_int($cursor['order'] ?? null), 422);
                $query->where(fn (Builder $q) => $q->where('orden_visualizacion', '>', $cursor['order'])
                    ->orWhere(fn (Builder $same) => $same->where('orden_visualizacion', $cursor['order'])->where('id', '>', $cursor['id'])));
            } else {
                $query->where('id', '>', $cursor['id']);
            }
        }
        if ($ordered) {
            $query->orderBy('orden_visualizacion');
        }
        $rows = $query->orderBy('id')->limit($size + 1)->get()->all();
        $more = count($rows) > $size;
        if ($more) {
            array_pop($rows);
        }
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return [$rows, $more && $last ? Crypt::encryptString(json_encode(['binding' => $binding, 'id' => $last->id, 'order' => $ordered ? (int) $last->orden_visualizacion : null], JSON_THROW_ON_ERROR)) : null];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public function execute(string $operation, ?string $resource, array $input, ?int $version, ?string $key, string $actor, string $correlation): array
    {
        $authorize = function () use ($operation, $resource, $input, $actor): void {
            if ($operation === 'define') {
                $this->permission($actor, 'requirements.manage');
            } elseif ($operation === 'submit') {
                $this->resident($input['residentId'], $input['projectId'] ?? null, $actor, 'evidence.submit');
            } elseif ($operation === 'review') {
                $this->reviewer($this->find('entregas_evidencia', (string) $resource), $actor);
            } else {
                abort(404);
            }
        };
        $authorize();
        ksort($input);
        $fingerprint = hash('sha256', json_encode([$operation, $resource, $input, $version], JSON_THROW_ON_ERROR));
        if ($key !== null) {
            abort_unless((bool) preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key), 422);
        }
        abort_unless($operation === 'define' && $resource !== null || $key !== null, 422);

        return DB::transaction(function () use ($operation, $resource, $input, $version, $key, $actor, $correlation, $fingerprint, $authorize): array {
            $this->gate(true);
            $authorize();
            $keyHash = $key === null ? null : hash('sha256', $actor.'|evidence-'.$operation.'|'.$resource.'|'.$key);
            if ($keyHash !== null) {
                $old = $this->table('claves_idempotencia')->where('clave', $keyHash)->first();
                if ($old !== null) {
                    abort_unless(hash_equals($old->hash_solicitud, $fingerprint) && $old->expira_en > now()->format('Y-m-d H:i:s.u'), 409);

                    return json_decode($old->cuerpo_respuesta, true, flags: JSON_THROW_ON_ERROR);
                }
            }
            $result = match ($operation) {
                'define' => $this->saveDefinition($resource, $input, $version),
                'submit' => $this->submit($input, $actor),
                'review' => $this->decide((string) $resource, $input, $actor),
                default => abort(404),
            };
            $this->audit(strtoupper($operation), $operation, $result['id'], $actor, $correlation);
            if ($keyHash !== null) {
                $this->table('claves_idempotencia')->insert(['id' => (string) Str::ulid(), 'clave' => $keyHash,
                    'hash_solicitud' => $fingerprint, 'cuerpo_respuesta' => json_encode($result, JSON_THROW_ON_ERROR), 'expira_en' => now()->addDay()]);
            }

            return $result;
        }, 3);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    private function saveDefinition(?string $id, array $input, ?int $version): array
    {
        $old = $id === null ? null : $this->find('definiciones_requisito', $id);
        if ($old) {
            abort_unless((int) $old->version === $version, 409);
        }
        $program = $input['programId'] ?? null;
        if ($program !== null) {
            abort_unless($this->find('programas_educativos', $program)->activo, 409);
        }
        $from = $input['validFrom'] ?? null;
        $until = $input['validUntil'] ?? null;
        if ($from !== null) {
            ResidencyRules::dates($from, $from);
        }
        if ($until !== null) {
            ResidencyRules::dates($until, $until);
        }
        abort_if($from !== null && $until !== null && $until < $from, 422);
        $mimes = $input['allowedMimes'];
        sort($mimes);
        abort_unless($mimes !== [] && count(array_unique($mimes)) === count($mimes) && array_diff($mimes, EvidenceRules::MIMES) === [], 422);
        abort_unless($input['maxBytes'] >= 1 && $input['maxBytes'] <= config('modernization.storage.max_bytes'), 422);
        $values = ['id_programa' => $program, 'codigo' => $input['code'], 'nombre' => $input['name'], 'etapa' => $input['stage'],
            'alcance' => $input['scope'], 'tipo_evidencia' => 'DOCUMENTO', 'obligatorio' => $input['required'], 'orden_visualizacion' => $input['displayOrder'],
            'vigente_desde' => $from, 'vigente_hasta' => $until, 'activo' => $input['active'],
            'mimes_permitidos' => json_encode($mimes, JSON_THROW_ON_ERROR), 'tamano_maximo' => $input['maxBytes']];
        if ($old && $this->table('entregas_evidencia')->where('id_requisito', $id)->exists()) {
            foreach (['id_programa', 'codigo', 'etapa', 'alcance', 'vigente_desde', 'vigente_hasta', 'mimes_permitidos', 'tamano_maximo'] as $field) {
                abort_unless($values[$field] === $old->$field || (string) $old->$field === (string) $values[$field], 409);
            }
        }
        abort_if($this->table('definiciones_requisito')->where('codigo', $input['code'])->where('id_programa', $program)
            ->when($old !== null, fn (Builder $q) => $q->where('id', '!=', $id))->exists(), 409);
        $id ??= (string) Str::ulid();
        $values += ['version' => $old === null ? 1 : (int) $old->version + 1, 'actualizado_en' => now()];
        if ($old === null) {
            $this->table('definiciones_requisito')->insert($values + ['id' => $id, 'creado_en' => now()]);
        } else {
            $this->table('definiciones_requisito')->where('id', $id)->update($values);
        }

        return $this->definition($this->find('definiciones_requisito', $id));
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    private function submit(array $input, string $actor): array
    {
        $definition = $this->applicable($input, $actor);
        $staged = $this->find('archivos_preparados', $input['stagedFileId']);
        abort_unless($staged->id_identidad_carga === $actor && $staged->id_residente === $input['residentId']
            && $staged->id_requisito === $input['requirementId'] && $staged->id_proyecto === ($input['projectId'] ?? null), 403);
        abort_unless($staged->estado === 'LISTO' && $staged->utilizado_en === null && $staged->expira_en > now()->format('Y-m-d H:i:s.u')
            && (int) $staged->version_requisito === (int) $definition->version, 409);
        $this->verifyFile($staged);
        $latest = $this->scope($input['residentId'], $input['projectId'] ?? null)->where('id_requisito', $definition->id)->orderByDesc('numero_version')->first();
        $previous = $latest === null ? 0 : (int) $latest->numero_version;
        abort_unless((int) $input['expectedSubmissionVersion'] === $previous && $previous < 65535 && ($latest === null || EvidenceRules::replaceable($latest->estado)), 409);
        if ($latest !== null) {
            $this->table('entregas_evidencia')->where('id', $latest->id)->update(['estado' => 'REEMPLAZADO', 'reemplazado_en' => now(), 'version' => (int) $latest->version + 1]);
        }
        $id = (string) Str::ulid();
        $this->table('entregas_evidencia')->insert(['id' => $id, 'id_requisito' => $definition->id, 'id_residente' => $input['residentId'],
            'id_proyecto' => $input['projectId'] ?? null, 'id_archivo' => $staged->id_archivo, 'id_identidad_envio' => $actor,
            'numero_version' => $previous + 1, 'estado' => 'ENVIADO', 'version' => 1, 'cantidad_revisiones' => 0,
            'instantanea_requisito' => json_encode($this->definition($definition), JSON_THROW_ON_ERROR), 'nombre_original' => $staged->nombre_original,
            'tipo_mime' => $staged->tipo_mime, 'tamano_bytes' => $staged->tamano_bytes, 'sha256' => $staged->sha256, 'enviado_en' => now(), 'creado_en' => now()]);
        $this->table('archivos_preparados')->where('id', $staged->id)->update(['estado' => 'UTILIZADO', 'utilizado_en' => now(), 'actualizado_en' => now()]);

        return $this->evidence($this->find('entregas_evidencia', $id), $actor, $this->find('residentes', $input['residentId'])->id_identidad);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    private function decide(string $id, array $input, string $actor): array
    {
        $evidence = $this->find('entregas_evidencia', $id);
        $this->reviewer($evidence, $actor);
        abort_unless($evidence->estado === 'ENVIADO' && (int) $input['expectedReviewNumber'] === (int) $evidence->cantidad_revisiones + 1, 409);
        $this->verifyFile($evidence);
        $state = EvidenceRules::decisionState($input['decision'], $input['observations'] ?? null);
        $review = (string) Str::ulid();
        $this->table('revisiones_evidencia')->insert(['id' => $review, 'id_entrega' => $id, 'id_identidad_revisor' => $actor,
            'numero_revision' => $input['expectedReviewNumber'], 'estado_anterior' => $evidence->estado, 'decision' => $input['decision'],
            'observaciones' => $input['observations'] ?? null, 'calificacion' => $input['grade'] ?? null, 'decidido_en' => now(), 'creado_en' => now()]);
        $this->table('entregas_evidencia')->where('id', $id)->update(['estado' => $state, 'cantidad_revisiones' => $input['expectedReviewNumber'], 'version' => (int) $evidence->version + 1]);

        return $this->evidence($this->find('entregas_evidencia', $id), $actor, $this->find('residentes', $evidence->id_residente)->id_identidad);
    }

    private function verifyFile(object $row): void
    {
        $metadata = $this->files->metadata($row->id_archivo);
        abort_unless($metadata && $metadata['status'] === 'LIMPIO' && $metadata['sha256'] === $row->sha256
            && $metadata['size'] === (int) $row->tamano_bytes && $metadata['mime'] === $row->tipo_mime
            && $metadata['ownerId'] === ($row->id_identidad_envio ?? $row->id_identidad_carga), 503);
        try {
            $this->files->bytes($row->id_archivo);
        } catch (Throwable) {
            abort(503);
        }
    }

    /** @return array<string, mixed> */
    private function definition(object $row): array
    {
        return ['id' => $row->id, 'version' => (int) $row->version, 'code' => $row->codigo, 'name' => $row->nombre,
            'programId' => $row->id_programa, 'stage' => $row->etapa, 'scope' => $row->alcance, 'required' => (bool) $row->obligatorio,
            'displayOrder' => (int) $row->orden_visualizacion, 'validFrom' => $row->vigente_desde, 'validUntil' => $row->vigente_hasta,
            'active' => (bool) $row->activo, 'allowedMimes' => json_decode($row->mimes_permitidos ?? '[]', true, flags: JSON_THROW_ON_ERROR), 'maxBytes' => (int) $row->tamano_maximo];
    }

    /** @return array<string, mixed> */
    private function evidence(object $row, string $actor, ?string $owner, ?bool $reviewAllowed = null): array
    {
        return ['id' => $row->id, 'version' => (int) $row->version, 'requirementId' => $row->id_requisito,
            'residentId' => $row->id_residente, 'projectId' => $row->id_proyecto, 'submissionVersion' => (int) $row->numero_version,
            'status' => $row->estado, 'reviewNumber' => (int) $row->cantidad_revisiones,
            'requirementSnapshot' => json_decode($row->instantanea_requisito, true, flags: JSON_THROW_ON_ERROR),
            'originalName' => $row->nombre_original, 'mime' => $row->tipo_mime, 'size' => (int) $row->tamano_bytes, 'sha256' => $row->sha256,
            'submittedAt' => Carbon::parse($row->enviado_en, 'UTC')->toISOString(),
            'replacedAt' => $row->reemplazado_en ? Carbon::parse($row->reemplazado_en, 'UTC')->toISOString() : null,
            'canReview' => $row->estado === 'ENVIADO' && $row->id_identidad_envio !== $actor && $owner !== $actor
                && ($reviewAllowed ?? $this->authorization->allows($actor, 'residencies.evidence.review'))];
    }

    /** @return array<string, mixed> */
    private function review(object $row): array
    {
        return ['id' => $row->id, 'reviewNumber' => (int) $row->numero_revision, 'previousStatus' => $row->estado_anterior,
            'decision' => $row->decision, 'observations' => $row->observaciones, 'grade' => $row->calificacion === null ? null : (float) $row->calificacion,
            'decidedAt' => Carbon::parse($row->decidido_en, 'UTC')->toISOString()];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public function stage(string $path, string $name, array $input, string $key, string $actor, string $correlation): array
    {
        $this->applicable($input, $actor);
        abort_unless(is_file($path) && filesize($path) > 0 && filesize($path) <= config('modernization.storage.max_bytes')
            && (bool) preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key), 422);
        $name = EvidenceRules::originalName($name);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $size = filesize($path);
        $hash = hash_file('sha256', $path);
        ksort($input);
        $fingerprint = hash('sha256', json_encode([$input, $name, $mime, $size, $hash], JSON_THROW_ON_ERROR));
        $slot = DB::transaction(function () use ($input, $actor, $key, $fingerprint, $name, $mime, $size): object {
            $this->gate(true);
            $definition = $this->applicable($input, $actor);
            abort_unless($size <= $definition->tamano_maximo && in_array($mime, json_decode($definition->mimes_permitidos, true, flags: JSON_THROW_ON_ERROR), true), 422);
            $keyHash = hash('sha256', $actor.'|document-stage|'.$key);
            $old = $this->table('archivos_preparados')->where('clave', $keyHash)->first();
            if ($old) {
                abort_unless(hash_equals($old->hash_solicitud, $fingerprint) && $old->expira_en > now()->format('Y-m-d H:i:s.u')
                    && in_array($old->estado, ['LISTO', 'UTILIZADO'], true), 409);

                return $old;
            }
            abort_if($this->table('archivos_preparados')->where('id_identidad_carga', $actor)->where('creado_en', '>=', now()->subDay())->count() >= 100, 429);
            $id = (string) Str::ulid();
            $this->table('archivos_preparados')->insert(['id' => $id, 'clave' => $keyHash, 'hash_solicitud' => $fingerprint,
                'id_requisito' => $input['requirementId'], 'id_residente' => $input['residentId'], 'id_proyecto' => $input['projectId'] ?? null,
                'id_identidad_carga' => $actor, 'version_requisito' => $definition->version, 'estado' => 'RESERVADO', 'nombre_original' => $name,
                'tamano_bytes' => $size, 'expira_en' => now()->addDay(), 'creado_en' => now(), 'actualizado_en' => now()]);

            return $this->find('archivos_preparados', $id);
        }, 3);
        if ($slot->estado !== 'RESERVADO') {
            return $this->prepared($slot);
        }
        $file = null;
        $failure = null;
        try {
            $file = $this->files->store($path, $actor);
        } catch (Throwable) {
            $failure = 503;
        }
        $metadata = $file === null ? null : $this->files->metadata($file);
        if ($failure === null && (! $metadata || $metadata['status'] !== 'LIMPIO')) {
            $failure = 422;
        }
        if ($failure === null && ($metadata['sha256'] !== $hash || $metadata['mime'] !== $mime || $metadata['size'] !== $size || $metadata['ownerId'] !== $actor)) {
            $failure = 503;
        }
        $result = DB::transaction(function () use ($slot, $file, $metadata, $failure, $input, $actor, $correlation): array {
            $this->gate(true);
            $status = $failure;
            try {
                $current = $this->applicable($input, $actor);
                if ((int) $current->version !== (int) $slot->version_requisito) {
                    $status = 409;
                }
            } catch (HttpExceptionInterface $exception) {
                $status = $exception->getStatusCode();
            }
            $this->table('archivos_preparados')->where('id', $slot->id)->update(['id_archivo' => $file,
                'estado' => $status === null ? 'LISTO' : 'FALLIDO', 'tipo_mime' => $metadata['mime'] ?? null,
                'tamano_bytes' => $metadata['size'] ?? $slot->tamano_bytes, 'sha256' => $metadata['sha256'] ?? null, 'actualizado_en' => now()]);
            $this->audit($status === null ? 'EVIDENCE_STAGED' : 'EVIDENCE_STAGE_FAILED', 'prepared-file', $slot->id, $actor, $correlation);

            return ['status' => $status, 'result' => $this->prepared($this->find('archivos_preparados', $slot->id))];
        }, 3);
        abort_if($result['status'] !== null, $result['status'] ?? 503);

        return $result['result'];
    }

    /** @return array<string, mixed> */
    private function prepared(object $row): array
    {
        return ['id' => $row->id, 'status' => 'READY', 'residentId' => $row->id_residente, 'projectId' => $row->id_proyecto,
            'requirementId' => $row->id_requisito, 'requirementVersion' => (int) $row->version_requisito,
            'originalName' => $row->nombre_original, 'mime' => $row->tipo_mime, 'size' => (int) $row->tamano_bytes,
            'sha256' => $row->sha256, 'expiresAt' => Carbon::parse($row->expira_en, 'UTC')->toISOString()];
    }

    public function download(string $evidence, string $actor): array
    {
        return DB::transaction(function () use ($evidence, $actor): array {
            $this->gate(false);
            $row = $this->find('entregas_evidencia', $evidence);
            $this->resident($row->id_residente, $row->id_proyecto, $actor);
            $this->verifyFile($row);
            try {
                $bytes = $this->files->bytes($row->id_archivo);
            } catch (Throwable) {
                abort(503);
            }
            $extension = match ($bytes['mime']) {
                'application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'text/plain' => 'txt', default => abort(503)
            };
            $this->audit('EVIDENCE_DOWNLOADED', 'evidence', $evidence, $actor, (string) Str::uuid());

            return $bytes + ['name' => 'evidence-'.$evidence.'.'.$extension];
        });
    }
}
