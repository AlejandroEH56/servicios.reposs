<?php

namespace App\Modules\Residencies\Infrastructure\Persistence;

use App\Modules\IAM\Application\AuthorizationDirectory;
use App\Modules\IAM\Application\IdentityDirectory;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Residencies\Application\ResidencyDirectory;
use App\Modules\Residencies\Domain\Services\ResidencyRules;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResidencyStore implements ResidencyDirectory
{
    private const TABLES = ['modalities' => 'modalidades', 'sectors' => 'sectores', 'business-lines' => 'ramos', 'programs' => 'programas_educativos', 'periods' => 'periodos', 'residents' => 'residentes', 'companies' => 'empresas', 'contacts' => 'contactos_empresa', 'projects' => 'proyectos', 'internal-advisers' => 'asesores_internos_proyecto', 'external-advisers' => 'asesores_externos_proyecto'];

    private const CATALOGS = ['modalities', 'sectors', 'business-lines', 'programs', 'periods'];

    public function __construct(private AuthorizationDirectory $authorization, private IdentityDirectory $identities, private OrganizationDirectory $organization) {}

    private function table(string $kind): Builder
    {
        abort_unless(isset(self::TABLES[$kind]), 404);

        return DB::table('residencias_'.self::TABLES[$kind]);
    }

    private function find(string $kind, string $id): object
    {
        $row = $this->table($kind)->where('id', $id)->first();
        abort_if($row === null, 404);

        return $row;
    }

    private function allowed(string $actor, string $permission, ?string $owner = null): bool
    {
        return $this->authorization->allows($actor, 'residencies.'.$permission, $owner);
    }

    private function requirePermission(string $actor, string $permission, ?string $owner = null): void
    {
        abort_unless($this->allowed($actor, $permission, $owner), 403);
    }

    private function residentAccess(object $resident, string $actor, string $permission): void
    {
        $this->requirePermission($actor, $permission, $resident->id_identidad);
    }

    private function projectAccess(object $project, string $actor, string $permission, bool $write = false): object
    {
        $resident = $this->find('residents', $project->id_residente);
        $this->residentAccess($resident, $actor, $permission);
        if ($write) {
            abort_unless($project->estado === 'BORRADOR' && $resident->estado === 'ACTIVO' && $this->find('periods', $project->id_periodo)->estado === 'ACTIVO' && $this->find('companies', $project->id_empresa)->estado === 'ACTIVO', 409);
        }

        return $resident;
    }

    private function companyContactsAccess(object $company, string $actor): void
    {
        abort_unless($this->canReadContacts($company, $actor), 403);
    }

    private function canReadContacts(object $company, string $actor): bool
    {
        if ($this->allowed($actor, 'files.read') || $this->allowed($actor, 'companies.manage', $company->id_identidad_creadora)) {
            return true;
        }
        $resident = $this->table('residents')->where('id_identidad', $actor)->first();

        return $resident !== null && $this->allowed($actor, 'files.read', $actor) && $this->table('projects')->where('id_residente', $resident->id)->where('id_empresa', $company->id)->exists();
    }

    public function read(string $kind, ?string $record, ?string $parent, array $filters, string $actor): array
    {
        return DB::transaction(function () use ($kind, $record, $parent, $filters, $actor): array {
            DB::table('residencias_controles')->where('codigo', 'WRITER')->sharedLock()->firstOrFail();

            return $this->readConsistent($kind, $record, $parent, $filters, $actor);
        }, 3);
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed> */
    private function readConsistent(string $kind, ?string $record, ?string $parent, array $filters, string $actor): array
    {
        if ($kind === 'me') {
            $resident = $this->table('residents')->where('id_identidad', $actor)->first();
            if ($resident === null) {
                return ['resident' => null, 'projects' => ['items' => [], 'nextCursor' => null]];
            }
            $this->residentAccess($resident, $actor, 'files.read');

            return ['resident' => $this->present('residents', $resident, $actor, true), 'projects' => $this->read('projects', null, null, ['residentId' => $resident->id], $actor)];
        }
        if ($kind === 'identity-candidates') {
            $this->requirePermission($actor, 'residents.enroll');

            return ['items' => $this->identities->search($filters['q'] ?? ''), 'nextCursor' => null];
        }
        if ($kind === 'adviser-candidates') {
            $this->requirePermission($actor, 'advisers.assign', $actor);

            return ['items' => $this->organization->advisers($filters['q'] ?? ''), 'nextCursor' => null];
        }
        $query = $this->table($kind);
        if ($kind === 'residents') {
            if ($record === null) {
                $this->requirePermission($actor, 'files.read');
            } else {
                $this->residentAccess($this->find($kind, $record), $actor, 'files.read');
            }
        }
        if ($kind === 'projects') {
            if ($record !== null) {
                $this->projectAccess($this->find($kind, $record), $actor, 'files.read');
            } elseif (! $this->allowed($actor, 'files.read')) {
                $this->requirePermission($actor, 'files.read', $actor);
                $query->whereIn('id_residente', $this->table('residents')->where('id_identidad', $actor)->select('id'));
            }
            if (isset($filters['residentId'])) {
                $query->where('id_residente', $filters['residentId']);
            }
        }
        if ($kind === 'contacts') {
            abort_if($parent === null, 404);
            $this->companyContactsAccess($this->find('companies', $parent), $actor);
            $query->where('id_empresa', $parent);
        }
        if ($record !== null) {
            $row = $query->where('id', $record)->first();
            abort_if($row === null, 404);

            return $this->present($kind, $row, $actor, true);
        }
        $size = (int) ($filters['pageSize'] ?? 25);
        $binding = hash('sha256', json_encode([$kind, $parent, $actor, $filters['q'] ?? '', $filters['active'] ?? null, $filters['residentId'] ?? null, $size], JSON_THROW_ON_ERROR));
        if (isset($filters['cursor'])) {
            try {
                $cursor = json_decode(Crypt::decryptString($filters['cursor']), true, flags: JSON_THROW_ON_ERROR);
                abort_unless(is_array($cursor) && ($cursor['binding'] ?? null) === $binding && Str::isUlid($cursor['after'] ?? ''), 422);
            } catch (\Throwable) {
                abort(422);
            }
            $query->where('id', '>', $cursor['after']);
        }
        if (isset($filters['active'])) {
            if (in_array($kind, ['companies', 'residents', 'periods'], true)) {
                $query->where('estado', (string) $filters['active'] === '1' ? '=' : '!=', 'ACTIVO');
            } elseif ($kind !== 'projects') {
                $query->where('activo', (bool) $filters['active']);
            }
        }
        if (($filters['q'] ?? '') !== '') {
            $column = match ($kind) {
                'companies' => 'razon_social','residents','contacts' => 'nombres',default => 'nombre'
            };
            $pattern = '%'.strtr($filters['q'], ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->whereRaw($column." LIKE ? ESCAPE '!'", [$pattern]);
        }
        $rows = $query->orderBy('id')->limit($size + 1)->get();
        $more = $rows->count() > $size;
        $rows = $rows->take($size);
        $last = $rows->last();
        $companyAccess = $kind === 'companies' ? ['global' => $this->allowed($actor, 'companies.manage'), 'self' => $this->allowed($actor, 'companies.manage', $actor)] : null;

        return ['items' => $rows->map(fn (object $row): array => $this->present($kind, $row, $actor, false, $companyAccess))->all(), 'nextCursor' => $more && $last !== null ? Crypt::encryptString(json_encode(['binding' => $binding, 'after' => $last->id], JSON_THROW_ON_ERROR)) : null];
    }

    /** @param array<string, mixed> $input */
    private function authorizeWrite(string $kind, ?string $record, ?string $parent, array $input, string $actor): void
    {
        if (in_array($kind, self::CATALOGS, true)) {
            $this->requirePermission($actor, 'catalogs.manage');
        } elseif ($kind === 'residents') {
            if ($record === null) {
                $this->requirePermission($actor, 'residents.enroll');
            } else {
                $this->residentAccess($this->find($kind, $record), $actor, 'profile.manage');
            }
        } elseif ($kind === 'companies') {
            $this->requirePermission($actor, $record === null ? 'companies.create' : 'companies.manage', $record === null ? $actor : $this->find($kind, $record)->id_identidad_creadora);
        } elseif ($kind === 'contacts') {
            abort_if($parent === null, 404);
            $company = $this->find('companies', $parent);
            $this->requirePermission($actor, 'companies.manage', $company->id_identidad_creadora);
            abort_unless($company->estado === 'ACTIVO', 409);
            if ($record !== null) {
                abort_unless($this->find($kind, $record)->id_empresa === $parent, 404);
            }
        } elseif ($kind === 'projects') {
            if ($record === null) {
                $resident = $this->find('residents', $input['residentId']);
                $this->residentAccess($resident, $actor, 'projects.manage');
                abort_unless($resident->estado === 'ACTIVO', 409);
            } else {
                $this->projectAccess($this->find($kind, $record), $actor, 'projects.manage', true);
            }
        } elseif (in_array($kind, ['internal-advisers', 'external-advisers'], true)) {
            abort_if($parent === null, 404);
            $this->projectAccess($this->find('projects', $parent), $actor, 'advisers.assign', true);
            if ($record !== null) {
                abort_unless($this->find($kind, $record)->id_proyecto === $parent, 404);
            }
        } else {
            abort(404);
        }
    }

    public function execute(string $kind, ?string $record, ?string $parent, array $input, ?int $version, ?string $key, string $actor, string $correlation): array
    {
        if ($kind === 'companies') {
            $input['rfc'] = ResidencyRules::rfc($input['rfc']);
        }
        $this->authorizeWrite($kind, $record, $parent, $input, $actor);
        if ($record === null) {
            abort_unless(is_string($key) && preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key), 422);
        }
        ksort($input);
        $fingerprint = hash('sha256', json_encode([$kind, $record, $parent, $input, $version], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($kind, $record, $parent, $input, $version, $key, $actor, $correlation, $fingerprint): array {
            DB::table('residencias_controles')->where('codigo', 'WRITER')->lockForUpdate()->firstOrFail();
            $this->authorizeWrite($kind, $record, $parent, $input, $actor);
            $keyHash = $record === null ? hash('sha256', $actor.'|'.$kind.'|'.$parent.'|'.$key) : null;
            if ($keyHash !== null) {
                $previous = DB::table('residencias_claves_idempotencia')->where('clave', $keyHash)->first();
                if ($previous !== null) {
                    abort_unless(hash_equals($previous->hash_solicitud, $fingerprint) && $previous->expira_en > now()->format('Y-m-d H:i:s.u'), 409);

                    return json_decode($previous->cuerpo_respuesta, true, flags: JSON_THROW_ON_ERROR);
                }
            }
            $row = $record === null ? null : $this->find($kind, $record);
            $adviser = in_array($kind, ['internal-advisers', 'external-advisers'], true);
            if ($adviser) {
                abort_unless($version !== null && (int) $this->find('projects', $parent)->version === $version, 409);
            } elseif ($row !== null) {
                abort_unless((int) $row->version === $version, 409);
            }
            $result = match (true) {
                in_array($kind, self::CATALOGS, true) => $this->saveCatalog($kind, $row, $input),
                $kind === 'residents' => $this->saveResident($row, $input, $actor),
                $kind === 'companies' => $this->saveCompany($row, $input, $actor),
                $kind === 'contacts' => $this->saveContact($row, $parent, $input),
                $kind === 'projects' => $this->saveProject($row, $input),
                $adviser => $this->saveAdviser($kind, $row, $parent, $input),
                default => abort(404),
            };
            $id = $result['id'];
            DB::table('compartido_registros_auditoria')->insert(['id' => (string) Str::ulid(), 'ocurrido_en' => now(), 'id_identidad_actor' => $actor, 'nombre_contexto' => 'Residencies', 'accion' => $row === null ? 'CREATE' : 'UPDATE', 'tipo_sujeto' => $kind, 'id_sujeto' => $id, 'id_correlacion' => $correlation]);
            if (! $adviser) {
                $result = $this->present($kind, $this->find($kind, $id), $actor, true);
            }
            if ($keyHash !== null) {
                DB::table('residencias_claves_idempotencia')->insert(['id' => (string) Str::ulid(), 'clave' => $keyHash, 'hash_solicitud' => $fingerprint, 'cuerpo_respuesta' => json_encode($result, JSON_THROW_ON_ERROR), 'expira_en' => now()->addDay()]);
            }

            return $result;
        }, 3);
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed> */
    private function save(string $kind, ?object $row, array $values): array
    {
        $id = $row === null ? (string) Str::ulid() : $row->id;
        $values['version'] = $row === null ? 1 : (int) $row->version + 1;
        $values['actualizado_en'] = now();
        if ($row === null) {
            $this->table($kind)->insert($values + ['id' => $id, 'creado_en' => now()]);
        } else {
            $this->table($kind)->where('id', $id)->update($values);
        }

        return ['id' => $id];
    }

    private function unique(string $kind, string $column, mixed $value, ?object $row): void
    {
        if ($value !== null) {
            abort_if($this->table($kind)->where($column, $value)->when($row !== null, fn (Builder $q) => $q->where('id', '!=', $row->id))->exists(), 409);
        }
    }

    private function activeReference(string $kind, string $id): object
    {
        $row = $this->table($kind)->where('id', $id)->first();
        abort_if($row === null, 422);
        abort_unless(in_array($kind, ['companies', 'periods', 'residents'], true) ? $row->estado === 'ACTIVO' : (bool) $row->activo, 409);

        return $row;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    private function saveCatalog(string $kind, ?object $row, array $input): array
    {
        $this->unique($kind, 'codigo', $input['code'], $row);
        $values = ['codigo' => $input['code'], 'nombre' => $input['name']];
        if ($kind === 'periods') {
            ResidencyRules::dates($input['startsOn'], $input['endsOn']);
            $status = $input['status'];
            if ($row === null) {
                abort_unless(in_array($status, ['PLANIFICADO', 'ACTIVO'], true), 409);
            } elseif ($status !== $row->estado) {
                abort_unless(in_array($status, match ($row->estado) {
                    'PLANIFICADO' => ['ACTIVO', 'ARCHIVADO'],'ACTIVO' => ['CERRADO'],'CERRADO' => ['ARCHIVADO'],default => []
                }, true), 409);
            }
            if ($row !== null && $this->table('projects')->where('id_periodo', $row->id)->exists()) {
                abort_unless($row->fecha_inicio === $input['startsOn'] && $row->fecha_fin === $input['endsOn'] && (int) $row->duracion_meses === (int) $input['durationMonths'], 409);
            }
            $values += ['fecha_inicio' => $input['startsOn'], 'fecha_fin' => $input['endsOn'], 'duracion_meses' => (int) $input['durationMonths'], 'cantidad_reportes_parciales' => ResidencyRules::partialReports((int) $input['durationMonths']), 'cantidad_reportes_finales' => 1, 'estado' => $status];
        } else {
            $values['activo'] = (bool) $input['active'];
        }
        if ($kind === 'programs') {
            $this->activeReference('modalities', $input['modalityId']);
            if (isset($input['validFrom'],$input['validUntil'])) {
                ResidencyRules::dates($input['validFrom'], $input['validUntil']);
            }
            if ($row !== null && $this->table('projects')->where('id_programa', $row->id)->exists()) {
                abort_unless($row->id_modalidad === $input['modalityId'] && $row->vigente_desde === ($input['validFrom'] ?? null) && $row->vigente_hasta === ($input['validUntil'] ?? null), 409);
            }
            $values += ['id_modalidad' => $input['modalityId'], 'vigente_desde' => $input['validFrom'] ?? null, 'vigente_hasta' => $input['validUntil'] ?? null];
        }
        if ($row !== null && ($values['activo'] ?? true) === false) {
            $dependencies = match ($kind) {
                'modalities' => [['programs', 'id_modalidad']],'programs' => [['residents', 'id_programa'], ['projects', 'id_programa']],'sectors' => [['companies', 'id_sector']],'business-lines' => [['companies', 'id_ramo']],default => []
            };
            foreach ($dependencies as [$child,$column]) {
                abort_if($this->table($child)->where($column, $row->id)->exists(), 409);
            }
        }

        return $this->save($kind, $row, $values);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    private function saveResident(?object $row, array $input, string $actor): array
    {
        $program = $this->activeReference('programs', $input['programId']);
        if ($row === null) {
            $identity = $this->identities->find($input['identityId']);
            abort_unless($identity !== null && $identity['status'] === 'ACTIVA', 422);
            $this->unique('residents', 'id_identidad', $identity['id'], null);
            $this->unique('residents', 'numero_control', $input['controlNumber'], null);
            $values = ['id_identidad' => $identity['id'], 'numero_control' => $input['controlNumber'], 'correo_institucional' => $identity['email'], 'estado' => 'ACTIVO'];
        } else {
            abort_unless(in_array($row->estado, ['ACTIVO', 'REGISTRO_INICIAL'], true), 409);
            if ($row->id_programa !== $program->id) {
                $this->requirePermission($actor, 'residents.enroll');
                abort_if($this->table('projects')->where('id_residente', $row->id)->exists(), 409);
            }
            $values = ['estado' => $row->estado];
        }
        $this->unique('residents', 'curp', $input['curp'] ?? null, $row);
        $values += ['id_programa' => $program->id] + $this->personValues($input) + ['domicilio' => $input['address'] ?? null, 'ciudad' => $input['city'] ?? null, 'numero_seguro_social' => $input['socialSecurityNumber'] ?? null, 'telefono' => $input['phone'] ?? null, 'telefono_celular' => $input['mobilePhone'] ?? null, 'curp' => $input['curp'] ?? null];

        return $this->save('residents', $row, $values);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    private function personValues(array $input): array
    {
        return ['nombres' => $input['givenNames'], 'primer_apellido' => $input['firstSurname'], 'segundo_apellido' => $input['secondSurname'] ?? null];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    private function saveCompany(?object $row, array $input, string $actor): array
    {
        $this->unique('companies', 'rfc', $input['rfc'], $row);
        if ($row !== null) {
            abort_unless($row->rfc === $input['rfc'], 409);
        }
        foreach (['sectorId' => 'sectors', 'businessLineId' => 'business-lines'] as $field => $kind) {
            if (isset($input[$field])) {
                $this->activeReference($kind, $input[$field]);
            }
        }
        if ($row !== null && $input['status'] !== 'ACTIVO') {
            abort_if($this->table('projects')->where('id_empresa', $row->id)->whereNotIn('estado', ['CANCELADO', 'ARCHIVADO'])->exists(), 409);
        }
        $values = ['razon_social' => $input['name'], 'rfc' => $input['rfc'], 'id_sector' => $input['sectorId'] ?? null, 'id_ramo' => $input['businessLineId'] ?? null, 'mision' => $input['mission'] ?? null, 'domicilio' => $input['address'] ?? null, 'ciudad' => $input['city'] ?? null, 'codigo_postal' => $input['postalCode'] ?? null, 'telefono' => $input['phone'] ?? null, 'correo' => $input['email'] ?? null, 'fax' => $input['fax'] ?? null, 'estado' => $input['status']];
        if ($row === null) {
            $values['id_identidad_creadora'] = $actor;
        }

        return $this->save('companies', $row, $values);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    private function saveContact(?object $row, string $parent, array $input): array
    {
        if ($row !== null && ! $input['active']) {
            abort_if($this->table('external-advisers')->join('residencias_proyectos as project', 'project.id', '=', 'residencias_asesores_externos_proyecto.id_proyecto')->where('id_contacto_empresa', $row->id)->whereNotIn('project.estado', ['CANCELADO', 'ARCHIVADO'])->whereRaw('COALESCE(residencias_asesores_externos_proyecto.fecha_fin, project.fecha_fin) >= ?', [now('America/Mexico_City')->toDateString()])->exists(), 409);
        }

        return $this->save('contacts', $row, $this->personValues($input) + ['id_empresa' => $parent, 'nombre_puesto' => $input['position'] ?? null, 'nombre_grado' => $input['degree'] ?? null, 'correo' => $input['email'] ?? null, 'telefono' => $input['phone'] ?? null, 'activo' => (bool) $input['active']]);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    private function saveProject(?object $row, array $input): array
    {
        $resident = $this->activeReference('residents', $input['residentId']);
        $period = $this->activeReference('periods', $input['periodId']);
        $this->activeReference('companies', $input['companyId']);
        $program = $this->activeReference('programs', $input['programId']);
        abort_unless($resident->id_programa === $program->id, 422);
        ResidencyRules::contained($input['startsOn'], $input['endsOn'], $period->fecha_inicio, $period->fecha_fin);
        if ($program->vigente_desde !== null) {
            abort_if($input['startsOn'] < $program->vigente_desde, 409);
        }
        if ($program->vigente_hasta !== null) {
            abort_if($input['endsOn'] > $program->vigente_hasta, 409);
        }
        if ($row !== null) {
            abort_unless($row->id_residente === $resident->id && $row->id_periodo === $period->id && $row->id_programa === $program->id, 409);
            foreach (['internal-advisers', 'external-advisers'] as $kind) {
                $assignments = $this->table($kind)->where('id_proyecto', $row->id)->get();
                if ($assignments->isNotEmpty()) {
                    abort_unless($row->id_empresa === $input['companyId'], 409);
                }
                foreach ($assignments as $assignment) {
                    ResidencyRules::contained($assignment->fecha_inicio, $assignment->fecha_fin ?? $input['endsOn'], $input['startsOn'], $input['endsOn']);
                }
            }
        }
        $others = $this->table('projects')->where('id_residente', $resident->id)->when($row !== null, fn (Builder $q) => $q->where('id', '!=', $row->id));
        abort_if((clone $others)->where('id_periodo', $period->id)->exists(), 409);
        abort_if((clone $others)->where('estado', '!=', 'CANCELADO')->where('fecha_inicio', '<=', $input['endsOn'])->where('fecha_fin', '>=', $input['startsOn'])->exists(), 409);

        return $this->save('projects', $row, ['id_residente' => $resident->id, 'id_periodo' => $period->id, 'id_empresa' => $input['companyId'], 'id_programa' => $program->id, 'nombre' => $input['name'], 'numero_vacantes' => (int) $input['vacancies'], 'fecha_inicio' => $input['startsOn'], 'fecha_fin' => $input['endsOn'], 'estado' => $row === null ? 'BORRADOR' : $row->estado]);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    private function saveAdviser(string $kind, ?object $row, string $parent, array $input): array
    {
        $project = $this->find('projects', $parent);
        if ($row !== null) {
            abort_unless($row->fecha_fin === null && ($input['endsOn'] ?? null) !== null, 409);
            ResidencyRules::contained($row->fecha_inicio, $input['endsOn'], $project->fecha_inicio, $project->fecha_fin);
            $result = $this->save($kind, $row, ['fecha_fin' => $input['endsOn']]);
        } else {
            ResidencyRules::contained($input['startsOn'], $input['endsOn'] ?? $project->fecha_fin, $project->fecha_inicio, $project->fecha_fin);
            $internal = $kind === 'internal-advisers';
            $column = $internal ? 'id_empleado' : 'id_contacto_empresa';
            $reference = $input[$internal ? 'employeeId' : 'contactId'];
            $siblings = $this->table($kind)->where('id_proyecto', $project->id)->get();
            abort_if($siblings->count() >= 100, 409);
            foreach ($siblings as $other) {
                if (($reference === $other->$column || ($other->codigo_rol === 'PRINCIPAL' && $input['roleCode'] === 'PRINCIPAL')) && ResidencyRules::overlaps($input['startsOn'], $input['endsOn'] ?? $project->fecha_fin, $other->fecha_inicio, $other->fecha_fin ?? $project->fecha_fin)) {
                    abort(409);
                }
            }
            if ($internal) {
                $employee = $this->organization->employee($reference);
                abort_unless($employee['status'] === 'ACTIVO' && $employee['isAdviser'], 409);
                $name = trim($employee['givenNames'].' '.$employee['firstSurname'].' '.($employee['secondSurname'] ?? ''));
                $positions = array_values(array_filter($employee['positions'], fn (array $position): bool => $position['active']));
                usort($positions, fn (array $a, array $b): int => [$a['source'] === 'MICROSOFT' ? 0 : 1, $a['sourceOrder'] ?? PHP_INT_MAX, $a['id']] <=> [$b['source'] === 'MICROSOFT' ? 0 : 1, $b['sourceOrder'] ?? PHP_INT_MAX, $b['id']]);
                $position = $positions[0]['name'] ?? null;
                $degree = $employee['degrees'][0]['abbreviation'] ?? $employee['degrees'][0]['levelName'] ?? null;
            } else {
                $contact = $this->find('contacts', $reference);
                abort_unless($contact->id_empresa === $project->id_empresa && $contact->activo, 422);
                $name = trim($contact->nombres.' '.$contact->primer_apellido.' '.($contact->segundo_apellido ?? ''));
                $position = $contact->nombre_puesto;
            }
            abort_if(mb_strlen($name) > 500, 422);
            $values = ['id_proyecto' => $parent, $column => $reference, 'codigo_rol' => $input['roleCode'], 'nombre_instantanea' => $name, 'puesto_instantanea' => $position, 'fecha_inicio' => $input['startsOn'], 'fecha_fin' => $input['endsOn'] ?? null];
            if ($internal) {
                $values['grado_instantanea'] = $degree;
            }
            $result = $this->save($kind, null, $values);
        }
        $this->table('projects')->where('id', $project->id)->update(['version' => (int) $project->version + 1, 'actualizado_en' => now()]);

        return $this->present($kind, $this->find($kind, $result['id']), '') + ['projectVersion' => (int) $project->version + 1];
    }

    /** @param array{global: bool, self: bool}|null $companyAccess
     * @return array<string, mixed> */
    private function present(string $kind, object $row, string $actor, bool $detail = false, ?array $companyAccess = null): array
    {
        $base = ['id' => $row->id, 'version' => (int) $row->version, 'kind' => $kind];
        if (in_array($kind, self::CATALOGS, true)) {
            $base += ['code' => $row->codigo, 'name' => $row->nombre];
            if ($kind === 'periods') {
                return $base + ['startsOn' => $row->fecha_inicio, 'endsOn' => $row->fecha_fin, 'durationMonths' => (int) $row->duracion_meses, 'partialReports' => (int) $row->cantidad_reportes_parciales, 'finalReports' => (int) $row->cantidad_reportes_finales, 'status' => $row->estado];
            }
            $base['active'] = (bool) $row->activo;

            return $kind === 'programs' ? $base + ['modalityId' => $row->id_modalidad, 'validFrom' => $row->vigente_desde, 'validUntil' => $row->vigente_hasta] : $base;
        }
        if ($kind === 'residents') {
            $base += ['name' => trim($row->nombres.' '.$row->primer_apellido.' '.($row->segundo_apellido ?? '')), 'controlNumber' => $row->numero_control, 'programId' => $row->id_programa, 'status' => $row->estado];

            return $detail ? $base + ['identityId' => $row->id_identidad, 'givenNames' => $row->nombres, 'firstSurname' => $row->primer_apellido, 'secondSurname' => $row->segundo_apellido, 'email' => $row->correo_institucional, 'address' => $row->domicilio, 'city' => $row->ciudad, 'socialSecurityNumber' => $row->numero_seguro_social, 'phone' => $row->telefono, 'mobilePhone' => $row->telefono_celular, 'curp' => $row->curp] : $base;
        }
        if ($kind === 'companies') {
            $canManage = $companyAccess === null ? $this->allowed($actor, 'companies.manage', $row->id_identidad_creadora) : ($companyAccess['global'] || ($companyAccess['self'] && $row->id_identidad_creadora === $actor));
            $base += ['name' => $row->razon_social, 'rfc' => $row->rfc, 'status' => $row->estado, 'canManage' => $canManage];

            return $detail ? $base + ['canReadContacts' => $this->canReadContacts($row, $actor), 'sectorId' => $row->id_sector, 'businessLineId' => $row->id_ramo, 'mission' => $row->mision, 'address' => $row->domicilio, 'city' => $row->ciudad, 'postalCode' => $row->codigo_postal, 'phone' => $row->telefono, 'email' => $row->correo, 'fax' => $row->fax] : $base;
        }
        if ($kind === 'contacts') {
            return $base + ['companyId' => $row->id_empresa, 'name' => trim($row->nombres.' '.$row->primer_apellido.' '.($row->segundo_apellido ?? '')), 'givenNames' => $row->nombres, 'firstSurname' => $row->primer_apellido, 'secondSurname' => $row->segundo_apellido, 'position' => $row->nombre_puesto, 'degree' => $row->nombre_grado, 'email' => $row->correo, 'phone' => $row->telefono, 'active' => (bool) $row->activo];
        }
        if ($kind === 'projects') {
            $base += ['residentId' => $row->id_residente, 'periodId' => $row->id_periodo, 'companyId' => $row->id_empresa, 'programId' => $row->id_programa, 'name' => $row->nombre, 'vacancies' => (int) $row->numero_vacantes, 'startsOn' => $row->fecha_inicio, 'endsOn' => $row->fecha_fin, 'status' => $row->estado];
            if ($detail) {
                foreach (['internal-advisers' => 'internalAdvisers', 'external-advisers' => 'externalAdvisers'] as $adviserKind => $field) {
                    $assignments = $this->table($adviserKind)->where('id_proyecto', $row->id)->orderBy('fecha_inicio')->orderBy('id')->limit(101)->get();
                    abort_if($assignments->count() > 100, 503);
                    $base[$field] = $assignments->map(fn (object $assignment): array => $this->present($adviserKind, $assignment, $actor))->all();
                }
            }

            return $base;
        }

        return $base + ['projectId' => $row->id_proyecto, 'roleCode' => $row->codigo_rol, 'nameSnapshot' => $row->nombre_instantanea, 'positionSnapshot' => $row->puesto_instantanea, 'startsOn' => $row->fecha_inicio, 'endsOn' => $row->fecha_fin] + ($kind === 'internal-advisers' ? ['employeeId' => $row->id_empleado, 'degreeSnapshot' => $row->grado_instantanea] : ['contactId' => $row->id_contacto_empresa]);
    }
}
