<?php

namespace App\Modules\Organization\Infrastructure\Persistence;

use App\Modules\IAM\Application\AuthorizationDirectory;
use App\Modules\IAM\Application\IdentityDirectory;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Organization\Application\ProfileProjector;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganizationStore implements OrganizationDirectory
{
    public function __construct(private IdentityDirectory $identities, private AuthorizationDirectory $authorization, private ProfileProjector $profiles) {}

    public function identityCandidates(string $search): array
    {
        return $this->identities->search($search);
    }

    public function employees(?string $owner, string $search): array
    {
        return DB::table('organizacion_empleados')->when($owner !== null, fn (Builder $query) => $query->where('id_identidad', $owner))
            ->when($search !== '', fn (Builder $query) => $query->where('nombres', 'like', '%'.$search.'%'))
            ->orderBy('nombres')->limit(100)->pluck('id')->map(fn (string $id) => $this->employee($id))->all();
    }

    public function employee(string $employeeId): array
    {
        $row = DB::table('organizacion_empleados')->where('id', $employeeId)->first();
        abort_if($row === null, 404);
        $positions = DB::table('organizacion_asignaciones_puesto as position')
            ->leftJoin('organizacion_unidades as unit', 'unit.id', '=', 'position.id_unidad_organizacional')
            ->where('position.id_empleado', $employeeId)->orderBy('position.origen_dato')->orderBy('position.orden_en_origen')->orderBy('position.creado_en')
            ->get(['position.*', 'unit.nombre as unidad_nombre'])->map(fn (object $position) => [
                'id' => $position->id, 'unitId' => $position->id_unidad_organizacional, 'unitName' => $position->unidad_nombre,
                'name' => $position->nombre_puesto, 'source' => $position->origen_dato, 'sourceOrder' => $position->orden_en_origen,
                'startsOn' => $position->fecha_inicio, 'endsOn' => $position->fecha_fin,
                'active' => $position->fecha_fin === null && $position->fecha_inicio <= today()->toDateString(),
            ])->all();
        $degrees = DB::table('organizacion_grados_academicos as degree')->join('organizacion_niveles_academicos as level', 'level.id', '=', 'degree.id_nivel_academico')
            ->where('degree.id_empleado', $employeeId)->orderByDesc('level.orden_jerarquia')
            ->get(['degree.*', 'level.nombre as nivel_nombre'])->map(fn (object $degree) => [
                'id' => $degree->id, 'levelId' => $degree->id_nivel_academico, 'levelName' => $degree->nivel_nombre,
                'program' => $degree->nombre_programa, 'abbreviation' => $degree->siglas, 'obtainedOn' => $degree->fecha_obtencion,
            ])->all();

        return [
            'id' => $row->id, 'identityId' => $row->id_identidad, 'employeeNumber' => $row->numero_empleado,
            'givenNames' => $row->nombres, 'firstSurname' => $row->primer_apellido, 'secondSurname' => $row->segundo_apellido,
            'email' => $row->correo_institucional, 'status' => $row->estado, 'version' => $row->version,
            'profileVersion' => $row->version_perfil_microsoft, 'isAdviser' => (bool) $row->habilitado_asesoria,
            'positions' => $positions, 'degrees' => $degrees,
        ];
    }

    public function advisers(string $search): array
    {
        return DB::table('organizacion_empleados')->where('estado', 'ACTIVO')->where('habilitado_asesoria', true)
            ->when($search !== '', fn (Builder $query) => $query->where('nombres', 'like', '%'.$search.'%'))
            ->orderBy('nombres')->limit(100)->pluck('id')->map(function (string $id): array {
                $employee = $this->employee($id);

                return ['id' => $id, 'displayName' => trim($employee['givenNames'].' '.$employee['firstSurname'].' '.($employee['secondSurname'] ?? '')),
                    'positions' => array_values(array_filter($employee['positions'], fn (array $position) => $position['active'])),
                    'degrees' => $employee['degrees']];
            })->all();
    }

    public function units(): array
    {
        return DB::table('organizacion_unidades')->orderBy('codigo')->get()->map(fn (object $row) => [
            'id' => $row->id, 'parentId' => $row->id_padre, 'code' => $row->codigo, 'name' => $row->nombre,
            'kind' => $row->tipo_unidad, 'active' => (bool) $row->activo, 'version' => $row->version,
        ])->all();
    }

    public function levels(): array
    {
        return DB::table('organizacion_niveles_academicos')->orderBy('orden_jerarquia')->get()->map(fn (object $row) => [
            'id' => $row->id, 'code' => $row->codigo, 'name' => $row->nombre,
            'rank' => $row->orden_jerarquia, 'version' => $row->version,
        ])->all();
    }

    public function execute(string $action, ?string $resourceId, array $data, string $actor, string $correlation): array
    {
        return DB::transaction(function () use ($action, $resourceId, $data, $actor, $correlation): array {
            $subject = $resourceId;
            if (in_array($action, ['createUnit', 'updateUnit'], true)) {
                abort_unless($this->authorization->allows($actor, 'organization.unidades.gestionar'), 403);
                $result = $this->saveUnit($resourceId, $data);
                $subject = $result['id'];
            } elseif (in_array($action, ['createLevel', 'updateLevel'], true)) {
                abort_unless($this->authorization->allows($actor, 'organization.niveles.gestionar'), 403);
                $result = $this->saveLevel($resourceId, $data);
                $subject = $result['id'];
            } elseif ($action === 'createEmployee') {
                abort_unless($this->authorization->allows($actor, 'organization.empleados.gestionar'), 403);
                $identity = $this->identities->find($data['identityId']);
                abort_if($identity === null || $identity['status'] !== 'ACTIVA', 422);
                $id = (string) Str::ulid();
                DB::table('organizacion_empleados')->insert([
                    'id' => $id, 'id_identidad' => $identity['id'], 'numero_empleado' => $data['employeeNumber'] ?? null,
                    'nombres' => $data['givenNames'], 'primer_apellido' => $data['firstSurname'], 'segundo_apellido' => $data['secondSurname'] ?? null,
                    'correo_institucional' => $identity['email'], 'estado' => 'ACTIVO', 'habilitado_asesoria' => $data['isAdviser'] ?? false,
                    'version' => 1, 'version_perfil_microsoft' => 0, 'creado_en' => now(), 'actualizado_en' => now(),
                ]);
                $this->profiles->synchronize($identity['id']);
                $result = $this->employee($id);
                $subject = $id;
            } else {
                $employee = DB::table('organizacion_empleados')->where('id', $resourceId)->lockForUpdate()->first();
                abort_if($employee === null, 404);
                abort_if($employee->version !== $data['expectedVersion'], 409);
                $degreeAction = in_array($action, ['addDegree', 'removeDegree'], true);
                $permission = $degreeAction ? 'organization.grados.gestionar' : 'organization.empleados.gestionar';
                abort_unless($this->authorization->allows($actor, $permission, $employee->id_identidad), 403);
                abort_if($degreeAction && ! in_array($employee->estado, ['ACTIVO', 'EN_LICENCIA'], true), 409);
                if ($action !== 'updateEmployee') {
                    abort_if($employee->estado === 'ARCHIVADO', 409);
                }
                abort_if($action === 'syncProfile' && $employee->id_identidad === null, 422);
                match ($action) {
                    'updateEmployee' => DB::table('organizacion_empleados')->where('id', $resourceId)->update([
                        'nombres' => $data['givenNames'], 'primer_apellido' => $data['firstSurname'], 'segundo_apellido' => $data['secondSurname'] ?? null,
                        'numero_empleado' => $data['employeeNumber'] ?? null, 'estado' => $data['status'], 'habilitado_asesoria' => $data['isAdviser'],
                    ]),
                    'assignPosition' => $this->assignPosition($resourceId, $data),
                    'finishPosition' => $this->finishPosition($resourceId, $data),
                    'addDegree' => $this->addDegree($resourceId, $data),
                    'removeDegree' => $this->removeDegree($resourceId, $data),
                    'syncProfile' => $this->profiles->synchronize($employee->id_identidad),
                    default => abort(400),
                };
                if ($action !== 'syncProfile') {
                    DB::table('organizacion_empleados')->where('id', $resourceId)->update(['version' => $employee->version + 1, 'actualizado_en' => now()]);
                }
                $result = $this->employee($resourceId);
            }
            DB::table('compartido_registros_auditoria')->insert([
                'id' => (string) Str::ulid(), 'ocurrido_en' => now(), 'id_identidad_actor' => $actor,
                'nombre_contexto' => 'Organization', 'accion' => strtoupper($action), 'tipo_sujeto' => 'OrganizationResource',
                'id_sujeto' => $subject, 'id_correlacion' => $correlation, 'metadatos' => json_encode(['version' => $result['version']], JSON_THROW_ON_ERROR),
            ]);

            return $result;
        }, 3);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    private function saveUnit(?string $id, array $data): array
    {
        DB::table('organizacion_controles')->where('codigo', 'TREE')->lockForUpdate()->firstOrFail();
        $existing = $id === null ? null : DB::table('organizacion_unidades')->where('id', $id)->first();
        abort_if($id !== null && $existing === null, 404);
        abort_if($existing !== null && $existing->version !== $data['expectedVersion'], 409);
        $id ??= (string) Str::ulid();
        $parentId = $data['parentId'] ?? null;
        $visited = [$id => true];
        while ($parentId !== null) {
            abort_if(isset($visited[$parentId]), 409);
            $visited[$parentId] = true;
            $parent = DB::table('organizacion_unidades')->where('id', $parentId)->first();
            abort_if($parent === null || ! $parent->activo, 422);
            $parentId = $parent->id_padre;
        }
        if (! $data['active']) {
            abort_if(DB::table('organizacion_unidades')->where('id_padre', $id)->where('activo', true)->exists()
                || DB::table('organizacion_asignaciones_puesto')->where('id_unidad_organizacional', $id)->whereNull('fecha_fin')->exists(), 409);
        }
        $values = ['id_padre' => $data['parentId'] ?? null, 'codigo' => $data['code'], 'nombre' => $data['name'],
            'tipo_unidad' => $data['kind'], 'activo' => $data['active'], 'version' => $existing === null ? 1 : $existing->version + 1, 'actualizado_en' => now()];
        if ($existing === null) {
            DB::table('organizacion_unidades')->insert(['id' => $id, 'creado_en' => now()] + $values);
        } else {
            DB::table('organizacion_unidades')->where('id', $id)->update($values);
        }

        return collect($this->units())->firstWhere('id', $id);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    private function saveLevel(?string $id, array $data): array
    {
        $existing = $id === null ? null : DB::table('organizacion_niveles_academicos')->where('id', $id)->lockForUpdate()->first();
        abort_if($id !== null && $existing === null, 404);
        abort_if($existing !== null && $existing->version !== $data['expectedVersion'], 409);
        $id ??= (string) Str::ulid();
        $values = ['codigo' => $data['code'], 'nombre' => $data['name'], 'orden_jerarquia' => $data['rank'], 'version' => $existing === null ? 1 : $existing->version + 1];
        if ($existing === null) {
            DB::table('organizacion_niveles_academicos')->insert(['id' => $id] + $values);
        } else {
            DB::table('organizacion_niveles_academicos')->where('id', $id)->update($values);
        }

        return collect($this->levels())->firstWhere('id', $id);
    }

    /** @param array<string, mixed> $data */
    private function assignPosition(string $employeeId, array $data): void
    {
        DB::table('organizacion_controles')->where('codigo', 'TREE')->lockForUpdate()->firstOrFail();
        $unit = DB::table('organizacion_unidades')->where('id', $data['unitId'])->first();
        abort_if($unit === null || ! $unit->activo, 422);
        abort_if(DB::table('organizacion_asignaciones_puesto')->where('id_empleado', $employeeId)
            ->where('id_unidad_organizacional', $data['unitId'])->where('nombre_puesto', $data['name'])
            ->where('origen_dato', 'MANUAL')->whereNull('fecha_fin')->exists(), 409);
        DB::table('organizacion_asignaciones_puesto')->insert([
            'id' => (string) Str::ulid(), 'id_empleado' => $employeeId, 'id_unidad_organizacional' => $data['unitId'],
            'nombre_puesto' => $data['name'], 'origen_dato' => 'MANUAL', 'fecha_inicio' => $data['startsOn'],
            'creado_en' => now(), 'actualizado_en' => now(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function finishPosition(string $employeeId, array $data): void
    {
        $position = DB::table('organizacion_asignaciones_puesto')->where('id', $data['positionId'])->where('id_empleado', $employeeId)->first();
        abort_if($position === null, 404);
        abort_if($position->origen_dato !== 'MANUAL' || $position->fecha_fin !== null || $data['endsOn'] < $position->fecha_inicio, 409);
        DB::table('organizacion_asignaciones_puesto')->where('id', $position->id)->update(['fecha_fin' => $data['endsOn'], 'actualizado_en' => now()]);
    }

    /** @param array<string, mixed> $data */
    private function addDegree(string $employeeId, array $data): void
    {
        abort_unless(DB::table('organizacion_niveles_academicos')->where('id', $data['levelId'])->exists(), 422);
        DB::table('organizacion_grados_academicos')->insert([
            'id' => (string) Str::ulid(), 'id_empleado' => $employeeId, 'id_nivel_academico' => $data['levelId'],
            'nombre_programa' => $data['program'], 'siglas' => $data['abbreviation'] ?? null, 'fecha_obtencion' => $data['obtainedOn'] ?? null,
            'creado_en' => now(), 'actualizado_en' => now(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function removeDegree(string $employeeId, array $data): void
    {
        abort_unless(DB::table('organizacion_grados_academicos')->where('id', $data['degreeId'])->where('id_empleado', $employeeId)->exists(), 404);
        DB::table('organizacion_grados_academicos')->where('id', $data['degreeId'])->where('id_empleado', $employeeId)->delete();
    }
}
