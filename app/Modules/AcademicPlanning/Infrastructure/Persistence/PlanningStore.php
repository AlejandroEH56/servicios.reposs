<?php

namespace App\Modules\AcademicPlanning\Infrastructure\Persistence;

use App\Modules\AcademicPlanning\Application\PlanningDirectory;
use App\Modules\AcademicPlanning\Domain\Services\PlanningRules;
use App\Modules\IAM\Application\AuthorizationDirectory;
use App\Modules\Organization\Application\OrganizationDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PlanningStore implements PlanningDirectory
{
    private const TABLES = ['careers' => 'carreras', 'specialties' => 'especialidades', 'subjects' => 'asignaturas', 'curricula' => 'reticulas',
        'groups' => 'grupos', 'holiday-types' => 'tipos_dia_inhabil', 'semesters' => 'semestres', 'laboratories' => 'laboratorios', 'holidays' => 'dias_inhabiles'];

    private const FIELDS = ['code' => 'codigo', 'name' => 'nombre', 'shortName' => 'nombre_corto', 'active' => 'activo', 'careerId' => 'id_carrera',
        'specialtyId' => 'id_especialidad', 'subjectId' => 'id_asignatura', 'semesterId' => 'id_semestre', 'satca' => 'satca', 'startsOn' => 'fecha_inicio',
        'endsOn' => 'fecha_fin', 'status' => 'estado', 'capacity' => 'capacidad', 'responsibleEmployeeId' => 'id_empleado_responsable',
        'holidayTypeId' => 'id_tipo_dia_inhabil', 'laboratoryId' => 'id_laboratorio', 'startsAt' => 'inicio_en', 'endsAt' => 'fin_en'];

    public function __construct(private AuthorizationDirectory $authorization, private OrganizationDirectory $organization) {}

    private function table(string $kind): string
    {
        abort_unless(isset(self::TABLES[$kind]), 404);

        return 'planificacion_'.self::TABLES[$kind];
    }

    public function read(string $kind, ?string $id, array $filters): array
    {
        $table = $this->table($kind);
        if ($id !== null) {
            $row = DB::table($table)->where('id', $id)->first();
            abort_if($row === null, 404);

            return $this->present($kind, $row);
        }
        $size = (int) ($filters['pageSize'] ?? 25);
        $search = $filters['q'] ?? '';
        $active = $filters['active'] ?? null;
        $binding = hash('sha256', json_encode([$kind, $search, $size, $active], JSON_THROW_ON_ERROR));
        $after = null;
        if (isset($filters['cursor'])) {
            try {
                $cursor = json_decode(Crypt::decryptString($filters['cursor']), true, flags: JSON_THROW_ON_ERROR);
                abort_unless(is_array($cursor) && ($cursor['binding'] ?? null) === $binding && Str::isUlid($cursor['after'] ?? ''), 422);
                $after = $cursor['after'];
            } catch (\Throwable) {
                abort(422);
            }
        }
        $query = DB::table($table)->when($after !== null, fn ($q) => $q->where('id', '>', $after));
        if ($active !== null) {
            $stateKind = in_array($kind, ['semesters', 'laboratories'], true);
            $query->where($stateKind ? 'estado' : 'activo', $stateKind && ! (bool) $active ? '!=' : '=', $stateKind ? 'ACTIVO' : (bool) $active);
        }
        if ($search !== '') {
            $pattern = '%'.strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            if ($kind === 'curricula') {
                $query->whereIn('id_asignatura', DB::table('planificacion_asignaturas')->select('id')->whereRaw("nombre LIKE ? ESCAPE '!'", [$pattern]));
            } else {
                $query->whereRaw("nombre LIKE ? ESCAPE '!'", [$pattern]);
            }
        }
        $rows = $query->orderBy('id')->limit($size + 1)->get();
        $more = $rows->count() > $size;
        $rows = $rows->take($size);
        $last = $rows->last();

        return ['items' => $rows->map(fn (object $row) => $this->present($kind, $row))->all(),
            'nextCursor' => $more && $last !== null ? Crypt::encryptString(json_encode(['binding' => $binding, 'after' => $last->id], JSON_THROW_ON_ERROR)) : null];
    }

    public function execute(string $kind, string $action, ?string $id, array $data, ?int $version, ?string $key, string $actor, string $correlation): array
    {
        $table = $this->table($kind);
        abort_unless(in_array($action, ['create', 'update', 'activate', 'close', 'archive'], true), 400);
        $this->authorize($kind, $actor);
        if (array_key_exists('active', $data)) {
            $data['active'] = (bool) $data['active'];
        }
        $optional = match ($kind) {
            'careers' => ['shortName'], 'subjects' => ['satca'], 'curricula' => ['specialtyId'],
            'laboratories' => ['careerId', 'responsibleEmployeeId'], 'holidays' => ['laboratoryId'], default => [],
        };
        if (in_array($action, ['create', 'update'], true)) {
            foreach ($optional as $field) {
                $data[$field] ??= null;
            }
        }
        foreach ($data as $field => $value) {
            if (is_string($value) && in_array($field, ['code', 'name', 'shortName', 'satca'], true)) {
                $data[$field] = PlanningRules::text($value);
            }
        }
        if ($kind === 'semesters' && in_array($action, ['create', 'update'], true)) {
            PlanningRules::dates($data['startsOn'], $data['endsOn']);
        }
        if ($kind === 'holidays') {
            $data['startsAt'] = PlanningRules::instant($data['startsAt']);
            $data['endsAt'] = PlanningRules::instant($data['endsAt']);
            if ($data['endsAt'] <= $data['startsAt']) {
                throw new InvalidArgumentException('Holiday end must follow its start.');
            }
        }
        ksort($data);
        $fingerprint = hash('sha256', json_encode([$kind, $action, $id, $data, $version], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($kind, $action, $id, $data, $version, $key, $actor, $correlation, $table, $fingerprint): array {
            DB::table('planificacion_controles')->where('codigo', 'WRITER')->lockForUpdate()->firstOrFail();
            $this->authorize($kind, $actor);
            $keyHash = $key === null ? null : hash('sha256', $actor.'|'.$kind.'|'.$action.'|'.($id ?? '').'|'.$key);
            if ($keyHash !== null) {
                $previous = DB::table('planificacion_claves_idempotencia')->where('clave', $keyHash)->first();
                if ($previous !== null) {
                    abort_unless(hash_equals($previous->hash_solicitud, $fingerprint) && $previous->expira_en > now()->format('Y-m-d H:i:s.u'), 409);

                    return json_decode($previous->cuerpo_respuesta, true, flags: JSON_THROW_ON_ERROR);
                }
            }
            $row = $id === null ? null : DB::table($table)->where('id', $id)->lockForUpdate()->first();
            abort_if($id !== null && $row === null, 404);
            abort_if($row !== null && (int) $row->version !== $version, 409);
            if (! in_array($action, ['create', 'update'], true)) {
                abort_unless($kind === 'semesters' && $row !== null, 400);
                try {
                    $status = PlanningRules::transition($row->estado, $action);
                } catch (InvalidArgumentException) {
                    abort(409);
                }
                abort_if($action === 'activate' && DB::table($table)->where('estado', 'ACTIVO')->where('id', '!=', $id)->exists(), 409);
                $values = ['estado' => $status];
            } else {
                abort_if($kind === 'semesters' && $row !== null && in_array($row->estado, ['CERRADO', 'ARCHIVADO'], true), 409);
                $this->references($kind, $data);
                $this->protectDependents($kind, $id, $data, $row);
                $values = [];
                foreach ($data as $field => $value) {
                    abort_unless(isset(self::FIELDS[$field]), 422);
                    $values[self::FIELDS[$field]] = $value;
                }
                if ($kind === 'semesters' && $row === null) {
                    $values['estado'] = 'PLANIFICADO';
                }
            }
            $subject = $id ?? (string) Str::ulid();
            $values['version'] = $row === null ? 1 : (int) $row->version + 1;
            $values['actualizado_en'] = now();
            if ($row === null) {
                $values['id'] = $subject;
                $values['creado_en'] = now();
                DB::table($table)->insert($values);
            } else {
                DB::table($table)->where('id', $subject)->update($values);
            }
            DB::table('compartido_registros_auditoria')->insert(['id' => (string) Str::ulid(), 'ocurrido_en' => now(), 'id_identidad_actor' => $actor,
                'nombre_contexto' => 'AcademicPlanning', 'accion' => strtoupper($action), 'tipo_sujeto' => $kind, 'id_sujeto' => $subject, 'id_correlacion' => $correlation]);
            $result = $this->read($kind, $subject, []);
            if ($keyHash !== null) {
                DB::table('planificacion_claves_idempotencia')->insert(['id' => (string) Str::ulid(), 'clave' => $keyHash, 'hash_solicitud' => $fingerprint,
                    'cuerpo_respuesta' => json_encode($result, JSON_THROW_ON_ERROR), 'expira_en' => now()->addDay()]);
            }

            return $result;
        }, 3);
    }

    private function authorize(string $kind, string $actor): void
    {
        $permission = in_array($kind, ['semesters', 'laboratories', 'holidays'], true) ? 'planning.manage' : 'planning.catalogs.manage';
        abort_unless($this->authorization->allows($actor, $permission), 403);
    }

    /** @param array<string, mixed> $data */
    private function references(string $kind, array $data): void
    {
        foreach (['careerId' => 'careers', 'specialtyId' => 'specialties', 'subjectId' => 'subjects', 'semesterId' => 'semesters', 'holidayTypeId' => 'holiday-types', 'laboratoryId' => 'laboratories'] as $field => $target) {
            if (($data[$field] ?? null) === null) {
                continue;
            }
            $reference = DB::table($this->table($target))->where('id', $data[$field])->first();
            abort_if($reference === null, 422);
            $available = $target === 'semesters' ? in_array($reference->estado, ['PLANIFICADO', 'ACTIVO'], true)
                : ($target === 'laboratories' ? $reference->estado !== 'ARCHIVADO' : (bool) $reference->activo);
            abort_unless($available, 422);
            if ($field === 'specialtyId') {
                abort_unless($reference->id_carrera === $data['careerId'], 422);
            }
        }
        if (($data['responsibleEmployeeId'] ?? null) !== null) {
            try {
                $employee = $this->organization->employee($data['responsibleEmployeeId']);
            } catch (HttpException $exception) {
                if ($exception->getStatusCode() === 404) {
                    abort(422);
                }
                throw $exception;
            }
            abort_unless($employee['status'] === 'ACTIVO', 422);
        }
    }

    /** @param array<string, mixed> $data */
    private function protectDependents(string $kind, ?string $id, array $data, ?object $row): void
    {
        if ($id === null) {
            return;
        }
        if ($kind === 'specialties' && $row !== null && $data['careerId'] !== $row->id_carrera) {
            abort_if(DB::table('planificacion_reticulas')->where('id_especialidad', $id)->exists(), 409);
        }
        $inactive = ($data['active'] ?? true) === false || (isset($data['status']) && $data['status'] === 'ARCHIVADO');
        if (! $inactive) {
            return;
        }
        $dependencies = match ($kind) {
            'careers' => ['specialties' => 'id_carrera', 'curricula' => 'id_carrera', 'groups' => 'id_carrera', 'laboratories' => 'id_carrera'],
            'specialties' => ['curricula' => 'id_especialidad'], 'subjects' => ['curricula' => 'id_asignatura'],
            'holiday-types' => ['holidays' => 'id_tipo_dia_inhabil'], 'laboratories' => ['holidays' => 'id_laboratorio'], default => [],
        };
        foreach ($dependencies as $dependent => $column) {
            $query = DB::table($this->table($dependent))->where($column, $id);
            $query = $dependent === 'laboratories' ? $query->where('estado', '!=', 'ARCHIVADO') : $query->where('activo', true);
            abort_if($query->exists(), 409);
        }
    }

    /** @return array<string, mixed> */
    private function present(string $kind, object $row): array
    {
        $result = ['id' => $row->id, 'kind' => $kind, 'version' => (int) $row->version];
        foreach (self::FIELDS as $field => $column) {
            if (property_exists($row, $column)) {
                $value = $row->$column;
                $result[$field] = match ($field) {
                    'active' => (bool) $value, 'capacity' => (int) $value,
                    'startsAt', 'endsAt' => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->format('Y-m-d\TH:i:s.u\Z'),
                    default => $value,
                };
            }
        }
        $result['active'] ??= $row->estado === 'ACTIVO';
        if ($kind === 'curricula') {
            $result['name'] = (string) DB::table('planificacion_asignaturas')->where('id', $row->id_asignatura)->value('nombre');
        }

        return $result;
    }
}
