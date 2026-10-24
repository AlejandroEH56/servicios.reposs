<?php

namespace App\Modules\AcademicPlanning\Infrastructure\Persistence;

use App\Modules\AcademicPlanning\Application\LaboratoryCalendar;
use App\Modules\AcademicPlanning\Domain\Services\AvailabilityRules;
use App\Modules\IAM\Application\AuthorizationDirectory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class LaboratoryCalendarStore implements LaboratoryCalendar
{
    public function __construct(private AuthorizationDirectory $authorization) {}

    public function schedules(string $laboratory, array $filters): array
    {
        abort_unless(DB::table('planificacion_laboratorios')->where('id', $laboratory)->exists(), 404);
        abort_unless(DB::table('planificacion_semestres')->where('id', $filters['semesterId'])->exists(), 404);
        $size = (int) ($filters['pageSize'] ?? 25);
        $binding = hash('sha256', json_encode([$laboratory, $filters['semesterId'], $size], JSON_THROW_ON_ERROR));
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
        $rows = DB::table('planificacion_horarios')->where('id_laboratorio', $laboratory)->where('id_semestre', $filters['semesterId'])
            ->when($after !== null, fn ($query) => $query->where('id', '>', $after))->orderBy('id')->limit($size + 1)->get();
        $more = $rows->count() > $size;
        $rows = $rows->take($size);
        $last = $rows->last();

        return ['items' => $rows->map(fn (object $row): array => $this->present($row))->all(),
            'nextCursor' => $more && $last !== null ? Crypt::encryptString(json_encode(['binding' => $binding, 'after' => $last->id], JSON_THROW_ON_ERROR)) : null];
    }

    public function publish(string $laboratory, ?string $schedule, array $input, ?int $version, ?string $key, string $actor, string $correlation): array
    {
        abort_unless($this->authorization->allows($actor, 'planning.manage'), 403);
        AvailabilityRules::slot((int) $input['weekday'], $input['startsAtLocal'], $input['endsAtLocal']);
        $input['weekday'] = (int) $input['weekday'];
        $input['active'] = $schedule === null ? true : (bool) $input['active'];
        abort_unless($schedule !== null || (is_string($key) && preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key)), 422);
        ksort($input);
        $fingerprint = hash('sha256', json_encode([$laboratory, $schedule, $input, $version], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($laboratory, $schedule, $input, $version, $key, $actor, $correlation, $fingerprint): array {
            DB::table('planificacion_controles')->where('codigo', 'WRITER')->lockForUpdate()->firstOrFail();
            abort_unless($this->authorization->allows($actor, 'planning.manage'), 403);
            $lab = DB::table('planificacion_laboratorios')->where('id', $laboratory)->first();
            abort_if($lab === null, 404);
            $semester = DB::table('planificacion_semestres')->where('id', $input['semesterId'])->first();
            abort_if($semester === null, 422);
            abort_unless($lab->estado === 'ACTIVO' && $semester->estado === 'ACTIVO', 409);
            $keyHash = $schedule === null ? hash('sha256', $actor.'|calendar|'.$laboratory.'|'.$key) : null;
            if ($keyHash !== null) {
                $previous = DB::table('planificacion_claves_idempotencia')->where('clave', $keyHash)->first();
                if ($previous !== null) {
                    abort_unless(hash_equals($previous->hash_solicitud, $fingerprint) && $previous->expira_en > now()->format('Y-m-d H:i:s.u'), 409);

                    return json_decode($previous->cuerpo_respuesta, true, flags: JSON_THROW_ON_ERROR);
                }
            }
            $row = $schedule === null ? null : DB::table('planificacion_horarios')->where('id', $schedule)->where('id_laboratorio', $laboratory)->lockForUpdate()->first();
            abort_if($schedule !== null && $row === null, 404);
            abort_if($row !== null && ((int) $row->version !== $version || $row->id_semestre !== $input['semesterId']), 409);
            $siblings = DB::table('planificacion_horarios')->where('id_laboratorio', $laboratory)->where('id_semestre', $input['semesterId'])
                ->when($schedule !== null, fn ($query) => $query->where('id', '!=', $schedule));
            if ($input['active']) {
                abort_if((clone $siblings)->where('activo', true)->count() >= 200, 409);
                abort_if((clone $siblings)->where('activo', true)->where('dia_semana', $input['weekday'])
                    ->where('inicio_en', '<', $input['endsAtLocal'].':00')->where('fin_en', '>', $input['startsAtLocal'].':00')->exists(), 409);
            }
            abort_if((clone $siblings)->where('dia_semana', $input['weekday'])->where('inicio_en', $input['startsAtLocal'].':00')->where('fin_en', $input['endsAtLocal'].':00')->exists(), 409);
            $id = $schedule ?? (string) Str::ulid();
            $values = ['id_semestre' => $input['semesterId'], 'id_laboratorio' => $laboratory, 'dia_semana' => $input['weekday'],
                'inicio_en' => $input['startsAtLocal'].':00', 'fin_en' => $input['endsAtLocal'].':00', 'activo' => $input['active'],
                'version' => $row === null ? 1 : (int) $row->version + 1, 'actualizado_en' => now()];
            if ($row === null) {
                DB::table('planificacion_horarios')->insert($values + ['id' => $id, 'creado_en' => now()]);
            } else {
                DB::table('planificacion_horarios')->where('id', $id)->update($values);
            }
            DB::table('compartido_registros_auditoria')->insert(['id' => (string) Str::ulid(), 'ocurrido_en' => now(), 'id_identidad_actor' => $actor,
                'nombre_contexto' => 'AcademicPlanning', 'accion' => $row === null ? 'PUBLISH' : 'UPDATE', 'tipo_sujeto' => 'schedules', 'id_sujeto' => $id, 'id_correlacion' => $correlation]);
            $result = $this->present(DB::table('planificacion_horarios')->where('id', $id)->firstOrFail());
            if ($keyHash !== null) {
                DB::table('planificacion_claves_idempotencia')->insert(['id' => (string) Str::ulid(), 'clave' => $keyHash, 'hash_solicitud' => $fingerprint,
                    'cuerpo_respuesta' => json_encode($result, JSON_THROW_ON_ERROR), 'expira_en' => now()->addDay()]);
            }

            return $result;
        }, 3);
    }

    public function availability(string $laboratory, string $start, string $end): array
    {
        [$from, $until] = AvailabilityRules::window($start, $end);

        return DB::transaction(function () use ($laboratory, $from, $until): array {
            DB::table('planificacion_controles')->where('codigo', 'WRITER')->sharedLock()->firstOrFail();
            $lab = DB::table('planificacion_laboratorios')->where('id', $laboratory)->first();
            abort_if($lab === null, 404);
            $semester = DB::table('planificacion_semestres')->where('estado', 'ACTIVO')->first();
            $result = ['laboratoryId' => $laboratory, 'semesterId' => $semester?->id, 'timeZone' => AvailabilityRules::TIME_ZONE,
                'startAt' => AvailabilityRules::iso($from), 'endAt' => AvailabilityRules::iso($until), 'available' => false,
                'reservation' => false, 'basis' => 'PLANNING_CALENDAR', 'intervals' => [], 'reasonCode' => null];
            if ($lab->estado !== 'ACTIVO' || $semester === null) {
                $result['reasonCode'] = $lab->estado !== 'ACTIVO' ? 'LABORATORY_UNAVAILABLE' : 'NO_ACTIVE_SEMESTER';

                return $result;
            }
            $slots = DB::table('planificacion_horarios')->where('id_laboratorio', $laboratory)->where('id_semestre', $semester->id)->where('activo', true)->limit(201)->get();
            abort_if($slots->count() > 200, 503);
            $holidays = DB::table('planificacion_dias_inhabiles')->where('activo', true)
                ->where(fn ($query) => $query->whereNull('id_laboratorio')->orWhere('id_laboratorio', $laboratory))
                ->where('inicio_en', '<', $until->format('Y-m-d H:i:s.u'))->where('fin_en', '>', $from->format('Y-m-d H:i:s.u'))->limit(1001)->get();
            abort_if($holidays->count() > 1000, 422);
            try {
                $result['intervals'] = AvailabilityRules::calculate($from, $until, $semester->fecha_inicio, $semester->fecha_fin,
                    $slots->map(fn (object $row): array => ['weekday' => (int) $row->dia_semana, 'startsAtLocal' => substr($row->inicio_en, 0, 5), 'endsAtLocal' => substr($row->fin_en, 0, 5)])->all(),
                    $holidays->map(fn (object $row): array => ['startAt' => $row->inicio_en, 'endAt' => $row->fin_en])->all());
            } catch (InvalidArgumentException) {
                abort(503);
            }
            $result['available'] = count($result['intervals']) === 1 && $result['intervals'][0]['startAt'] === $result['startAt'] && $result['intervals'][0]['endAt'] === $result['endAt'];
            $result['reasonCode'] = $result['available'] ? null : ($slots->isEmpty() ? 'NO_SCHEDULE' : 'OUTSIDE_AVAILABLE_INTERVAL');

            return $result;
        }, 3);
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        return ['id' => $row->id, 'laboratoryId' => $row->id_laboratorio, 'semesterId' => $row->id_semestre,
            'weekday' => (int) $row->dia_semana, 'startsAtLocal' => substr($row->inicio_en, 0, 5), 'endsAtLocal' => substr($row->fin_en, 0, 5),
            'timeZone' => AvailabilityRules::TIME_ZONE, 'active' => (bool) $row->activo, 'version' => (int) $row->version];
    }
}
