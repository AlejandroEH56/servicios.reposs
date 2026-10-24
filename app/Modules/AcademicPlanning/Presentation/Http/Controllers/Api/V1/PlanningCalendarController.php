<?php

namespace App\Modules\AcademicPlanning\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\AcademicPlanning\Application\LaboratoryCalendar;
use App\Modules\AcademicPlanning\Presentation\Http\Requests\Api\V1\PublishScheduleRequest;
use App\Modules\AcademicPlanning\Presentation\Http\Resources\Api\V1\PlanningResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PlanningCalendarController extends Controller
{
    public function __construct(private LaboratoryCalendar $calendar) {}

    public function schedules(Request $request): JsonResponse
    {
        $filters = $request->validate(['semesterId' => ['required', 'ulid'], 'pageSize' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['sometimes', 'string', 'max:4096']]);
        abort_unless(array_diff(array_keys($request->query()), array_keys($filters)) === [], 422);

        return $this->response($this->calendar->schedules($request->route('laboratory'), $filters));
    }

    public function publish(PublishScheduleRequest $request): JsonResponse
    {
        $id = $request->route('schedule');
        $key = $id === null ? $request->header('Idempotency-Key') : null;
        $version = null;
        if ($id !== null) {
            abort_unless(preg_match('/^"([1-9][0-9]{0,9})"$/D', $request->header('If-Match', ''), $matches), 422);
            $version = (int) $matches[1];
        }
        try {
            $data = $this->calendar->publish($request->route('laboratory'), $id, $request->validated(), $version, $key,
                (string) $request->user()->getAuthIdentifier(), (string) $request->attributes->get('correlationId'));
        } catch (InvalidArgumentException) {
            abort(422);
        }

        return $this->response($data, $id === null ? 201 : 200);
    }

    public function availability(Request $request): JsonResponse
    {
        $filters = $request->validate(['startAt' => ['required', 'string', 'max:40'], 'endAt' => ['required', 'string', 'max:40']]);
        abort_unless(array_diff(array_keys($request->query()), array_keys($filters)) === [], 422);
        try {
            $data = $this->calendar->availability($request->route('laboratory'), $filters['startAt'], $filters['endAt']);
        } catch (InvalidArgumentException) {
            abort(422);
        }

        return $this->response($data);
    }

    /** @param array<string, mixed> $data */
    private function response(array $data, int $status = 200): JsonResponse
    {
        $response = (new PlanningResource(['data' => $data]))->response()->setStatusCode($status)->header('Cache-Control', 'no-store, private');
        if (isset($data['version'])) {
            $response->header('ETag', '"'.$data['version'].'"');
        }

        return $response;
    }
}
