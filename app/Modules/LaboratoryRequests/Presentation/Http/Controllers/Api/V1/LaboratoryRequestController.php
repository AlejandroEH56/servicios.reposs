<?php

namespace App\Modules\LaboratoryRequests\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\LaboratoryRequests\Application\RequestCapture;
use App\Modules\LaboratoryRequests\Presentation\Http\Requests\Api\V1\CaptureRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LaboratoryRequestController extends Controller
{
    public function __construct(private RequestCapture $capture) {}

    public function quote(Request $request): JsonResponse
    {
        $input = $request->validate(['laboratoryId' => ['required', 'ulid'], 'startAt' => ['required', 'string', 'max:40'], 'endAt' => ['required', 'string', 'max:40']]);
        abort_if(array_diff_key($request->all(), $input) !== [], 422);

        return $this->respond($this->capture->quote($input['laboratoryId'], $input['startAt'], $input['endAt']));
    }

    public function read(Request $request): JsonResponse
    {
        $id = $request->route('record');
        $input = $request->validate($id === null ? ['cursor' => ['sometimes', 'string', 'max:2048']] : []);
        abort_if(array_diff_key($request->all(), $input) !== [], 422);

        return $this->respond($this->capture->read($this->actor($request), $id, $input['cursor'] ?? null));
    }

    public function save(CaptureRequest $request): JsonResponse
    {
        $id = $request->route('record');

        return $this->respond($this->capture->save($this->actor($request), $id, $request->validated(),
            $id === null ? null : $this->version($request), $id === null ? $this->key($request) : null, $this->correlation($request)), $id === null ? 201 : 200);
    }

    public function submit(Request $request): JsonResponse
    {
        abort_if($request->all() !== [], 422);

        return $this->respond($this->capture->submit($this->actor($request), $request->route('record'), $this->version($request), $this->key($request), $this->correlation($request)));
    }

    protected function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }

    protected function correlation(Request $request): string
    {
        return (string) $request->attributes->get('correlationId');
    }

    protected function key(Request $request): string
    {
        $value = $request->header('Idempotency-Key', '');
        abort_unless(preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $value) === 1, 422);

        return $value;
    }

    protected function version(Request $request): int
    {
        abort_unless(preg_match('/^"([1-9][0-9]{0,9})"$/D', $request->header('If-Match', ''), $match) === 1, 422);

        return (int) $match[1];
    }

    /** @param array<string, mixed> $data */
    protected function respond(array $data, int $status = 200): JsonResponse
    {
        $response = response()->json(['data' => $data], $status)->header('Cache-Control', 'no-store, private');
        if (isset($data['version'])) {
            $response->header('ETag', '"'.$data['version'].'"');
        }

        return $response;
    }
}
