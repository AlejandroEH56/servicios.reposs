<?php

namespace App\Modules\Residencies\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Residencies\Application\ResidencyDirectory;
use App\Modules\Residencies\Presentation\Http\Requests\Api\V1\ResidencyRequest;
use App\Modules\Residencies\Presentation\Http\Resources\Api\V1\ResidencyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ResidencyController extends Controller
{
    public function __construct(private ResidencyDirectory $directory) {}

    public function read(Request $request): JsonResponse
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'pageSize' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['sometimes', 'string', 'max:4096'], 'active' => ['sometimes', 'in:0,1'], 'residentId' => ['sometimes', 'ulid']]);
        abort_unless(array_diff(array_keys($request->query()), array_keys($filters)) === [], 422);

        return $this->response($this->directory->read($request->route('kind'), $request->route('record'), $request->route('parent'), $filters, (string) $request->user()->getAuthIdentifier()));
    }

    public function write(ResidencyRequest $request): JsonResponse
    {
        $new = $request->isMethod('POST');
        $kind = $request->route('kind');
        $key = null;
        $version = null;
        if ($new) {
            $key = $request->header('Idempotency-Key');
            abort_unless(is_string($key) && preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key), 422);
        }
        if (! $new || in_array($kind, ['internal-advisers', 'external-advisers'], true)) {
            abort_unless(preg_match('/^"([1-9][0-9]{0,9})"$/D', $request->header('If-Match', ''), $matches), 422);
            $version = (int) $matches[1];
        }
        try {
            $result = $this->directory->execute($kind, $request->route('record'), $request->route('parent'), $request->validated(), $version, $key, (string) $request->user()->getAuthIdentifier(), (string) $request->attributes->get('correlationId'));
        } catch (InvalidArgumentException) {
            abort(422);
        }

        return $this->response($result, $new ? 201 : 200);
    }

    /** @param array<string, mixed> $data */
    private function response(array $data, int $status = 200): JsonResponse
    {
        $response = (new ResidencyResource(['data' => $data]))->response()->setStatusCode($status)->header('Cache-Control', 'no-store, private');
        if (isset($data['projectVersion']) || isset($data['version'])) {
            $response->header('ETag', '"'.($data['projectVersion'] ?? $data['version']).'"');
        }

        return $response;
    }
}
