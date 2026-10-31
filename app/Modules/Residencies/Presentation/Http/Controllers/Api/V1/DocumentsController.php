<?php

namespace App\Modules\Residencies\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Residencies\Application\DocumentWorkflow;
use App\Modules\Residencies\Presentation\Http\Requests\Api\V1\DocumentRequest;
use App\Modules\Residencies\Presentation\Http\Resources\Api\V1\ResidencyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

class DocumentsController extends Controller
{
    public function __construct(private DocumentWorkflow $workflow) {}

    public function read(Request $request): JsonResponse
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'pageSize' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['sometimes', 'string', 'max:4096']]);
        abort_unless(array_diff(array_keys($request->query()), array_keys($filters)) === [], 422);

        return $this->response($this->workflow->read($request->route('operation'), $request->route('record'), $filters, (string) $request->user()->getAuthIdentifier()));
    }

    public function write(DocumentRequest $request): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && (bool) preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key), 422);
        $operation = $request->route('operation');
        $actor = (string) $request->user()->getAuthIdentifier();
        $correlation = (string) $request->attributes->get('correlationId');
        try {
            if ($operation === 'stage-resource') {
                $file = $request->file('file');
                abort_unless($file instanceof UploadedFile, 422);
                $result = $this->workflow->stageResource($file->getPathname(), $key, $actor, $correlation);
            } else {
                $result = $this->workflow->execute($operation, $request->route('record'), $request->validated(), $key, $actor, $correlation);
            }
        } catch (InvalidArgumentException) {
            abort(422);
        }

        return $this->response($result, in_array($operation, ['issue', 'retry'], true) ? 202 : 201);
    }

    public function download(Request $request): Response
    {
        $file = $this->workflow->download($request->route('record'), (string) $request->user()->getAuthIdentifier());

        return response($file['content'])->withHeaders(['Content-Type' => $file['mime'], 'Content-Disposition' => 'attachment; filename="'.$file['name'].'"',
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'"]);
    }

    /** @param array<string,mixed> $data */
    private function response(array $data, int $status = 200): JsonResponse
    {
        $response = (new ResidencyResource(['data' => $data]))->response()->setStatusCode($status)->header('Cache-Control', 'no-store, private');
        if (isset($data['version'])) {
            $response->header('ETag', '"'.$data['version'].'"');
        }

        return $response;
    }
}
