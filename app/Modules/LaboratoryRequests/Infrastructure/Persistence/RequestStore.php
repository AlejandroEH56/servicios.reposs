<?php

namespace App\Modules\LaboratoryRequests\Infrastructure\Persistence;

use App\Modules\AcademicPlanning\Application\LaboratoryCalendar;
use App\Modules\AcademicPlanning\Application\PlanningDirectory;
use App\Modules\IAM\Application\AuthorizationDirectory;
use App\Modules\LaboratoryRequests\Application\RequestCapture;
use App\Modules\LaboratoryRequests\Domain\Services\RequestRules;
use App\Modules\Organization\Application\OrganizationDirectory;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RequestStore implements RequestCapture
{
    private const TABLE = 'laboratorios_solicitudes';

    private const DETAIL_TABLES = [
        'PRACTICA' => 'laboratorios_detalles_practica',
        'VARIA' => 'laboratorios_detalles_varios',
        'EXTRAORDINARIA' => 'laboratorios_detalles_extraordinarios',
    ];

    public function __construct(
        private AuthorizationDirectory $authorization,
        private LaboratoryCalendar $calendar,
        private PlanningDirectory $planning,
        private OrganizationDirectory $organization,
    ) {}

    public function quote(string $laboratory, string $start, string $end): array
    {
        try {
            RequestRules::window($start, $end, now()->toDateTimeImmutable());

            return $this->calendar->availability($laboratory, $start, $end);
        } catch (InvalidArgumentException) {
            abort(422, 'El intervalo debe ser futuro, del mismo día y entre las 07:00 y las 20:00.');
        }
    }

    public function read(string $actor, ?string $id, ?string $cursor = null): array
    {
        if ($id !== null) {
            return $this->present($this->owned($actor)->where('id', $id)->first() ?? abort(404));
        }
        $after = null;
        if ($cursor !== null) {
            try {
                [$owner, $after] = array_pad(explode('|', Crypt::decryptString($cursor), 2), 2, '');
                abort_unless($owner === $actor && Str::isUlid($after), 422);
            } catch (DecryptException) {
                abort(422);
            }
        }
        $rows = $this->owned($actor)->when($after !== null, fn (Builder $query) => $query->where('id', '<', $after))
            ->orderByDesc('id')->limit(26)->get();
        $items = $rows->take(25);

        return ['items' => $items->map(fn (object $row): array => $this->present($row))->all(),
            'nextCursor' => $rows->count() > 25 ? Crypt::encryptString($actor.'|'.$items->last()->id) : null];
    }

    public function save(string $actor, ?string $id, array $input, ?int $version, ?string $key, string $correlation): array
    {
        return DB::transaction(function () use ($actor, $id, $input, $version, $key, $correlation): array {
            $this->lock();
            $this->calendar->lockForCapture();
            $row = $id === null ? null : ($this->owned($actor)->where('id', $id)->lockForUpdate()->first() ?? abort(404));
            $type = $input['type'];
            abort_unless(is_string($type) && isset(self::DETAIL_TABLES[$type]), 422);
            $this->authorize($actor, $type);
            abort_if($row !== null && ($row->estado !== 'BORRADOR' || (int) $row->version !== $version || $row->tipo_solicitud !== $type), 409);
            $employee = $this->responsible($actor, $type, $input['laboratoryId']);
            $cache = $key === null ? null : $this->cacheKey($actor, 'create', $key);
            $fingerprint = $this->fingerprint($input);
            if ($cache !== null && ($previous = $this->replay($cache, $fingerprint)) !== null) {
                return $previous;
            }
            $quote = $this->validateWindow($input);
            $id ??= (string) Str::ulid();
            $values = [
                'id_laboratorio' => $input['laboratoryId'], 'id_semestre' => $quote['semesterId'],
                'titulo' => $input['title'], 'proposito' => $input['purpose'], 'insumos' => $input['supplies'] ?? null,
                'inicio_en' => $this->databaseTime($quote['startAt']), 'fin_en' => $this->databaseTime($quote['endAt']),
                'numero_asistentes' => $input['attendees'], 'version' => $row === null ? 1 : (int) $row->version + 1,
                'actualizado_en' => now(),
            ];
            if ($row === null) {
                DB::table(self::TABLE)->insert($values + ['id' => $id, 'id_identidad_solicitante' => $actor, 'tipo_solicitud' => $type, 'estado' => 'BORRADOR', 'creado_en' => now()]);
            } else {
                DB::table(self::TABLE)->where('id', $id)->update($values);
            }
            $details = $input[RequestRules::DETAILS[$type]];
            $fields = match ($type) {
                'PRACTICA' => ['id_carrera' => $details['careerId'], 'id_asignatura' => $details['subjectId'], 'id_grupo' => $details['groupId'],
                    'nombre_practica' => $details['name'], 'numero_practica' => $details['number'] ?? null],
                'VARIA' => ['codigo_tipo_uso' => $details['useCode'], 'descripcion' => $details['description']],
                'EXTRAORDINARIA' => ['justificacion' => $details['justification'], 'id_empleado_solicitante' => $employee],
            };
            DB::table(self::DETAIL_TABLES[$type])->updateOrInsert(['id_solicitud' => $id], $fields);
            $this->audit($actor, $id, $row === null ? 'CREATE_DRAFT' : 'UPDATE_DRAFT', $correlation);
            $result = $this->read($actor, $id);
            if ($cache !== null) {
                $this->remember($cache, $fingerprint, $result);
            }

            return $result;
        }, 3);
    }

    public function submit(string $actor, string $id, int $version, string $key, string $correlation): array
    {
        return DB::transaction(function () use ($actor, $id, $version, $key, $correlation): array {
            $this->lock();
            $this->calendar->lockForCapture();
            $row = $this->owned($actor)->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            $this->authorize($actor, $row->tipo_solicitud);
            $this->responsible($actor, $row->tipo_solicitud, $row->id_laboratorio);
            $cache = $this->cacheKey($actor, 'submit:'.$id, $key);
            $fingerprint = $this->fingerprint(['version' => $version]);
            if (($previous = $this->replay($cache, $fingerprint)) !== null) {
                return $previous;
            }
            abort_unless($row->estado === 'BORRADOR' && (int) $row->version === $version, 409);
            $quote = $this->validateWindow($this->present($row));
            abort_unless($quote['available'] && $quote['semesterId'] === $row->id_semestre, 409, 'El horario ya no está disponible para el semestre del borrador.');
            DB::table(self::TABLE)->where('id', $id)->update([
                'estado' => 'PENDIENTE', 'version' => $version + 1, 'enviado_en' => now(), 'actualizado_en' => now(),
            ]);
            $this->audit($actor, $id, 'SUBMIT', $correlation);
            $result = $this->read($actor, $id);
            $this->remember($cache, $fingerprint, $result);

            return $result;
        }, 3);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    private function validateWindow(array $input): array
    {
        $quote = $this->quote($input['laboratoryId'], $input['startAt'], $input['endAt']);
        abort_unless(is_string($quote['semesterId']), 409, 'No hay un semestre activo.');
        $lab = $this->planning->read('laboratories', $input['laboratoryId'], []);
        abort_unless($lab['active'], 409, 'El laboratorio no está activo.');
        abort_unless($input['attendees'] <= $lab['capacity'], 422, 'La cantidad de asistentes supera la capacidad.');
        $semester = $this->planning->read('semesters', $quote['semesterId'], []);
        $date = (new DateTimeImmutable($quote['startAt']))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d');
        abort_unless($date >= $semester['startsOn'] && $date <= $semester['endsOn'], 422, 'La fecha está fuera del semestre activo.');
        if ($input['type'] === 'PRACTICA') {
            $practice = $input['practice'];
            $group = $this->catalog('groups', $practice['groupId']);
            $this->catalog('careers', $practice['careerId']);
            $this->catalog('subjects', $practice['subjectId']);
            abort_unless($group['careerId'] === $practice['careerId'] && $group['semesterId'] === $quote['semesterId']
                && $this->planning->hasActiveCurriculum($practice['careerId'], $practice['subjectId']), 422, 'La carrera, asignatura y grupo deben corresponder al semestre.');
        }

        return $quote;
    }

    /** @return array<string, mixed> */
    private function catalog(string $kind, string $id): array
    {
        try {
            $record = $this->planning->read($kind, $id, []);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 404) {
                abort(422, 'Referencia académica no válida.');
            }
            throw $exception;
        }
        abort_unless($record['active'], 422, 'Referencia académica inactiva.');

        return $record;
    }

    private function authorize(string $actor, string $type): void
    {
        $permission = $type === 'EXTRAORDINARIA' ? 'laboratorios.solicitudes.extraordinarias.crear' : 'laboratorios.solicitudes.crear';
        abort_unless($this->authorization->allows($actor, $permission, $actor), 403);
    }

    private function responsible(string $actor, string $type, string $laboratory): ?string
    {
        if ($type !== 'EXTRAORDINARIA') {
            return null;
        }
        $lab = $this->planning->read('laboratories', $laboratory, []);
        $employee = $this->organization->employees($actor, '')[0] ?? null;
        abort_unless($employee !== null && $employee['status'] === 'ACTIVO' && $employee['id'] === ($lab['responsibleEmployeeId'] ?? null), 403);

        return $employee['id'];
    }

    private function owned(string $actor): Builder
    {
        return DB::table(self::TABLE)->where('id_identidad_solicitante', $actor);
    }

    private function lock(): void
    {
        DB::table('laboratorios_controles')->where('codigo', 'WRITER')->lockForUpdate()->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        $type = $row->tipo_solicitud;
        if (! is_string($type) || ! isset(self::DETAIL_TABLES[$type])) {
            throw new \UnexpectedValueException('Invalid stored laboratory request type.');
        }
        $detail = DB::table(self::DETAIL_TABLES[$type])->where('id_solicitud', $row->id)->firstOrFail();
        $value = match ($type) {
            'PRACTICA' => ['careerId' => $detail->id_carrera, 'subjectId' => $detail->id_asignatura, 'groupId' => $detail->id_grupo,
                'name' => $detail->nombre_practica, 'number' => $detail->numero_practica],
            'VARIA' => ['useCode' => $detail->codigo_tipo_uso, 'description' => $detail->descripcion],
            'EXTRAORDINARIA' => ['justification' => $detail->justificacion],
        };

        return ['id' => $row->id, 'laboratoryId' => $row->id_laboratorio, 'semesterId' => $row->id_semestre, 'type' => $type,
            'title' => $row->titulo, 'purpose' => $row->proposito, 'attendees' => (int) $row->numero_asistentes, 'supplies' => $row->insumos,
            'startAt' => $this->iso($row->inicio_en), 'endAt' => $this->iso($row->fin_en), 'status' => $row->estado,
            'version' => (int) $row->version, 'submittedAt' => $row->enviado_en === null ? null : $this->iso($row->enviado_en),
            RequestRules::DETAILS[$type] => $value];
    }

    private function iso(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }

    private function databaseTime(string $value): string
    {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function cacheKey(string $actor, string $action, string $key): string
    {
        return hash('sha256', $actor.'|'.$action.'|'.$key);
    }

    /** @param array<string, mixed> $input */
    private function fingerprint(array $input): string
    {
        ksort($input);
        foreach ($input as &$value) {
            if (is_array($value)) {
                ksort($value);
            }
        }
        unset($value);

        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed>|null */
    private function replay(string $key, string $fingerprint): ?array
    {
        $row = DB::table('laboratorios_claves_idempotencia')->where('clave', $key)->first();
        if ($row === null) {
            return null;
        }
        abort_unless($row->expira_en > now()->format('Y-m-d H:i:s.u') && hash_equals($row->hash_solicitud, $fingerprint), 409);

        return json_decode($row->cuerpo_respuesta, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $result */
    private function remember(string $key, string $fingerprint, array $result): void
    {
        DB::table('laboratorios_claves_idempotencia')->insert([
            'clave' => $key, 'hash_solicitud' => $fingerprint, 'cuerpo_respuesta' => json_encode($result, JSON_THROW_ON_ERROR), 'expira_en' => now()->addDay(),
        ]);
    }

    private function audit(string $actor, string $id, string $action, string $correlation): void
    {
        DB::table('compartido_registros_auditoria')->insert(['id' => (string) Str::ulid(), 'ocurrido_en' => now(), 'id_identidad_actor' => $actor,
            'nombre_contexto' => 'LaboratoryRequests', 'accion' => $action, 'tipo_sujeto' => 'LaboratoryRequest', 'id_sujeto' => $id, 'id_correlacion' => $correlation]);
    }
}
