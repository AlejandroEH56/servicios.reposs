<?php

namespace App\Modules\LaboratoryRequests\Infrastructure\Persistence;

use App\Modules\AcademicPlanning\Application\LaboratoryCalendar;
use App\Modules\AcademicPlanning\Application\PlanningDirectory;
use App\Modules\AcademicPlanning\Domain\Services\AvailabilityRules;
use App\Modules\IAM\Application\IdentityDirectory;
use App\Modules\LaboratoryRequests\Application\RequestDecisions;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DecisionStore implements RequestDecisions
{
    public function __construct(
        private RequestStore $capture,
        private RequestAccess $access,
        private LaboratoryCalendar $planningCalendar,
        private PlanningDirectory $planning,
        private IdentityDirectory $identities,
    ) {}

    public function workflow(string $actor, ?string $id = null, ?string $cursor = null): array
    {
        if ($id !== null) {
            $row = DB::table('laboratorios_solicitudes')->where('id', $id)->first() ?? abort(404);
            $this->access->view($actor, $row);

            return $this->capture->present($row);
        }
        $after = $this->cursor($actor, 'workflow', $cursor);
        $rows = DB::table('laboratorios_solicitudes')->where('id_identidad_solicitante', $actor)
            ->when($after !== null, fn (Builder $query) => $query->where('id', '<', $after))
            ->orderByDesc('id')->limit(26)->get();
        $items = $rows->take(25);

        return ['items' => $items->map(fn (object $row): array => $this->capture->present($row))->all(),
            'nextCursor' => $rows->count() > 25 ? Crypt::encryptString($actor.'|workflow|'.$items->last()->id) : null];
    }

    public function queue(string $actor, ?string $cursor = null): array
    {
        $global = $this->access->globalReviewer($actor);
        $laboratories = $global ? [] : $this->access->laboratories($actor);
        abort_unless($global || $laboratories !== [], 403);
        $after = $this->cursor($actor, 'queue', $cursor);
        $rows = DB::table('laboratorios_solicitudes')->where('estado', 'PENDIENTE')
            ->when(! $global, fn (Builder $query) => $query->whereIn('id_laboratorio', $laboratories))
            ->when($after !== null, fn (Builder $query) => $query->where('id', '<', $after))
            ->orderByDesc('id')->limit(26)->get();
        $items = $rows->take(25);

        return ['items' => $items->map(fn (object $row): array => $this->capture->present($row))->all(),
            'nextCursor' => $rows->count() > 25 ? Crypt::encryptString($actor.'|queue|'.$items->last()->id) : null];
    }

    public function decide(string $actor, string $id, array $input, int $version, string $key, string $correlation): array
    {
        abort_unless(in_array($input['decision'], ['AUTORIZADO', 'RECHAZADO'], true), 422);

        return $this->change($actor, $id, $input['decision'], $input['reason'], $version, $key, $correlation);
    }

    public function cancel(string $actor, string $id, string $reason, int $version, string $key, string $correlation): array
    {
        return $this->change($actor, $id, 'CANCELADO', $reason, $version, $key, $correlation);
    }

    /** @return array<string, mixed> */
    private function change(string $actor, string $id, string $status, string $reason, int $version, string $key, string $correlation): array
    {
        $reason = trim($reason);
        abort_unless(mb_strlen($reason) >= 3 && mb_strlen($reason) <= 2000, 422);

        return DB::transaction(function () use ($actor, $id, $status, $reason, $version, $key, $correlation): array {
            DB::table('laboratorios_controles')->where('codigo', 'WRITER')->lockForUpdate()->firstOrFail();
            $this->planningCalendar->lockForCapture();
            $row = DB::table('laboratorios_solicitudes')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            $this->access->view($actor, $row);
            if ($status !== 'CANCELADO') {
                abort_unless($this->access->canReview($actor, $row->id_laboratorio) && $row->id_identidad_solicitante !== $actor, 403,
                    'No puedes resolver una solicitud propia.');
            }
            $cache = hash('sha256', $actor.'|decision:'.$id.'|'.$key);
            $fingerprint = hash('sha256', json_encode([$status, $reason, $version], JSON_THROW_ON_ERROR));
            $old = DB::table('laboratorios_claves_idempotencia')->where('clave', $cache)->first();
            if ($old !== null) {
                abort_unless(hash_equals($old->hash_solicitud, $fingerprint) && $old->expira_en > now()->format('Y-m-d H:i:s.u'), 409);

                return json_decode($old->cuerpo_respuesta, true, flags: JSON_THROW_ON_ERROR);
            }
            abort_unless((int) $row->version === $version, 409);
            if ($status === 'CANCELADO') {
                abort_unless(in_array($row->estado, ['BORRADOR', 'PENDIENTE', 'AUTORIZADO'], true)
                    && new DateTimeImmutable($row->inicio_en, new DateTimeZone('UTC')) > now(), 409,
                    'Sólo se puede cancelar antes del inicio.');
            } else {
                abort_unless($row->estado === 'PENDIENTE', 409);
            }
            if ($status === 'AUTORIZADO') {
                $quote = $this->capture->quote($row->id_laboratorio, $this->iso($row->inicio_en), $this->iso($row->fin_en));
                $laboratory = $this->planning->read('laboratories', $row->id_laboratorio, []);
                abort_unless($quote['available'] && $quote['semesterId'] === $row->id_semestre
                    && $row->numero_asistentes <= $laboratory['capacity'], 409, 'El horario o la capacidad ya no están disponibles.');
            }
            $before = $row->estado;
            $values = ['estado' => $status, 'version' => $version + 1, 'actualizado_en' => now()];
            if ($status === 'CANCELADO') {
                $values['cancelado_en'] = now();
            }
            DB::table('laboratorios_solicitudes')->where('id', $id)->update($values);
            $current = DB::table('laboratorios_solicitudes')->where('id', $id)->firstOrFail();
            $result = $this->capture->present($current);
            $decision = (string) Str::ulid();
            $laboratory = $this->planning->read('laboratories', $row->id_laboratorio, []);
            $requester = $this->identities->find($row->id_identidad_solicitante);
            $academic = $row->tipo_solicitud === 'PRACTICA' ? [
                'career' => $this->planning->read('careers', $result['practice']['careerId'], [])['name'],
                'subject' => $this->planning->read('subjects', $result['practice']['subjectId'], [])['name'],
                'subjectCode' => $this->planning->read('subjects', $result['practice']['subjectId'], [])['code'],
                'group' => $this->planning->read('groups', $result['practice']['groupId'], [])['name'],
            ] : null;
            $snapshot = json_encode(['request' => $result,
                'laboratory' => $laboratory['name'], 'capacity' => $laboratory['capacity'], 'academic' => $academic,
                'requester' => $requester['displayName'] ?? 'Solicitante', 'requesterEmail' => $requester['email'] ?? null,
                'reviewer' => $this->identities->find($actor)['displayName'] ?? 'Responsable',
                'decisionId' => $decision, 'reason' => $reason, 'decidedAt' => now()->toISOString()], JSON_THROW_ON_ERROR);
            DB::table('laboratorios_decisiones_solicitud')->insert(['id' => $decision, 'id_solicitud' => $id,
                'id_identidad_decisor' => $actor, 'decision' => $status, 'observaciones' => $reason,
                'decidido_en' => now(), 'version_solicitud' => $version + 1, 'estado_anterior' => $before,
                'instantanea' => $snapshot, 'sha256_instantanea' => hash('sha256', $snapshot)]);
            DB::table('compartido_registros_auditoria')->insert(['id' => (string) Str::ulid(), 'ocurrido_en' => now(),
                'id_identidad_actor' => $actor, 'nombre_contexto' => 'LaboratoryRequests', 'accion' => $status,
                'tipo_sujeto' => 'LaboratoryRequest', 'id_sujeto' => $id, 'id_correlacion' => $correlation]);
            $event = (string) Str::ulid();
            $type = 'laboratory-requests.decision-recorded.v1';
            DB::table('compartido_mensajes_salida')->insert(['id' => $event, 'nombre_contexto' => 'LaboratoryRequests',
                'tipo_agregado' => 'LaboratoryRequest', 'id_agregado' => $id, 'tipo_evento' => $type,
                'contenido' => json_encode(['eventId' => $event, 'type' => $type, 'version' => 1, 'aggregateId' => $id,
                    'actor' => $actor, 'correlationId' => $correlation, 'occurredAt' => now()->toISOString(),
                    'data' => ['decisionId' => $decision]], JSON_THROW_ON_ERROR),
                'estado' => 'PENDING', 'ocurrido_en' => now(), 'disponible_en' => now()]);
            DB::table('laboratorios_claves_idempotencia')->insert(['clave' => $cache, 'hash_solicitud' => $fingerprint,
                'cuerpo_respuesta' => json_encode($result, JSON_THROW_ON_ERROR), 'expira_en' => now()->addDay()]);

            return $result;
        }, 3);
    }

    public function calendar(string $laboratory, string $start, string $end): array
    {
        try {
            [$from, $until] = AvailabilityRules::window($start, $end);
        } catch (InvalidArgumentException) {
            abort(422, 'Consulta un intervalo positivo de hasta 31 días.');
        }
        $this->planning->read('laboratories', $laboratory, []);
        $rows = DB::table('laboratorios_solicitudes')->where('id_laboratorio', $laboratory)->where('estado', 'AUTORIZADO')
            ->where('inicio_en', '<', $until->format('Y-m-d H:i:s.u'))->where('fin_en', '>', $from->format('Y-m-d H:i:s.u'))
            ->orderBy('inicio_en')->limit(1001)->get();
        abort_if($rows->count() > 1000, 422, 'Reduce el intervalo de consulta.');

        return ['laboratoryId' => $laboratory, 'timeZone' => 'America/Mexico_City',
            'items' => $rows->map(fn (object $row): array => ['startAt' => $this->iso($row->inicio_en),
                'endAt' => $this->iso($row->fin_en), 'status' => 'OCUPADO'])->all()];
    }

    public function history(string $actor, string $id): array
    {
        $row = DB::table('laboratorios_solicitudes')->where('id', $id)->first() ?? abort(404);
        $this->access->view($actor, $row);

        return ['items' => DB::table('laboratorios_decisiones_solicitud')->where('id_solicitud', $id)
            ->orderBy('version_solicitud')->get()->map(fn (object $decision): array => [
                'id' => $decision->id, 'status' => $decision->decision, 'previousStatus' => $decision->estado_anterior,
                'reason' => $decision->observaciones, 'version' => (int) $decision->version_solicitud,
                'decidedAt' => $this->iso($decision->decidido_en),
            ])->all()];
    }

    private function cursor(string $actor, string $kind, ?string $cursor): ?string
    {
        if ($cursor === null) {
            return null;
        }
        try {
            [$owner, $scope, $id] = array_pad(explode('|', Crypt::decryptString($cursor)), 3, '');
        } catch (DecryptException) {
            abort(422);
        }
        abort_unless($owner === $actor && $scope === $kind && Str::isUlid($id), 422);

        return $id;
    }

    private function iso(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }
}
