<?php

namespace App\Modules\AcademicPlanning\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\AcademicPlanning\Application\PlanningDirectory;
use App\Modules\AcademicPlanning\Presentation\Http\Requests\Api\V1\PlanningRequest;
use App\Modules\AcademicPlanning\Presentation\Http\Resources\Api\V1\PlanningResource;
use App\Modules\IAM\Application\AuthorizationDirectory;
use App\Modules\Organization\Application\OrganizationDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PlanningController extends Controller
{
    public function __construct(private PlanningDirectory $directory) {}

    public function read(Request $request): JsonResponse
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'pageSize' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:4096'], 'active' => ['sometimes', 'in:0,1']]);
        abort_unless(array_diff(array_keys($request->query()), array_keys($filters)) === [], 422);

        return $this->response($this->directory->read($request->route('kind') ?? $request->route('catalog'), $request->route('record'), $filters));
    }

    public function write(PlanningRequest $request): JsonResponse
    {
        $action = $request->route('operation');
        $key = $request->header('Idempotency-Key');
        if ($action !== 'update') {
            abort_unless(is_string($key) && preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key), 422);
        } else {
            $key = null;
        }
        $version = null;
        if ($action !== 'create') {
            abort_unless(preg_match('/^"([1-9][0-9]{0,9})"$/D', $request->header('If-Match', ''), $matches), 422);
            $version = (int) $matches[1];
        }
        try {
            $data = $this->directory->execute($request->kind(), $action, $request->route('record'), $request->validated(), $version, $key,
                (string) $request->user()->getAuthIdentifier(), (string) $request->attributes->get('correlationId'));
        } catch (InvalidArgumentException) {
            abort(422);
        }

        return $this->response($data, $action === 'create' ? 201 : 200);
    }

    public function responsibles(Request $request, AuthorizationDirectory $authorization, OrganizationDirectory $organization): JsonResponse
    {
        abort_unless($authorization->allows((string) $request->user()->getAuthIdentifier(), 'planning.manage'), 403);
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        abort_unless(array_diff(array_keys($request->query()), ['q']) === [], 422);

        return $this->response($organization->responsibleCandidates((string) $request->query('q', '')));
    }

    /** @param array<string, mixed>|list<array<string, mixed>> $data */
    private function response(array $data, int $status = 200): JsonResponse
    {
        $response = (new PlanningResource(['data' => $data]))->response()->setStatusCode($status)->header('Cache-Control', 'no-store, private');
        if (isset($data['version'])) {
            $response->header('ETag', '"'.$data['version'].'"');
        }

        return $response;
    }
}
