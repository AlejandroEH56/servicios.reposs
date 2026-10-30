<?php

namespace App\Modules\Residencies\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Residencies\Application\ProgressWorkflow;
use App\Modules\Residencies\Presentation\Http\Requests\Api\V1\ProgressRequest;
use App\Modules\Residencies\Presentation\Http\Resources\Api\V1\ResidencyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ProgressController extends Controller
{
    public function __construct(private ProgressWorkflow $workflow) {}

    public function read(Request $request): JsonResponse
    {
        $filters = $request->validate(['pageSize' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['sometimes', 'string', 'max:4096'], 'projectId' => ['sometimes', 'ulid']]);
        abort_unless(array_diff(array_keys($request->query()), array_keys($filters)) === [], 422);
        try {
            $result = $this->workflow->read($request->route('operation'), $request->route('record'), $filters, (string) $request->user()->getAuthIdentifier());
        } catch (InvalidArgumentException) {
            abort(422);
        }

        return $this->response($result);
    }

    public function write(ProgressRequest $request): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && (bool) preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key), 422);
        try {
            $result = $this->workflow->execute($request->route('operation'), $request->route('record'), $request->validated(), $key,
                (string) $request->user()->getAuthIdentifier(), (string) $request->attributes->get('correlationId'));
        } catch (InvalidArgumentException) {
            abort(422);
        }

        return $this->response($result, 201);
    }

    /** @param array<string,mixed> $data */
    private function response(array $data, int $status = 200): JsonResponse
    {
        $response = (new ResidencyResource(['data' => $data]))->response()->setStatusCode($status)->header('Cache-Control', 'no-store, private');
        if (isset($data['version']) || isset($data['projectVersion'])) {
            $response->header('ETag', '"'.($data['version'] ?? $data['projectVersion']).'"');
        }

        return $response;
    }
}
