<?php

namespace App\Modules\Residencies\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Residencies\Application\EvidenceWorkflow;
use App\Modules\Residencies\Presentation\Http\Requests\Api\V1\EvidenceRequest;
use App\Modules\Residencies\Presentation\Http\Resources\Api\V1\ResidencyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

class EvidenceController extends Controller
{
    public function __construct(private EvidenceWorkflow $workflow) {}

    public function read(Request $request): JsonResponse
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'pageSize' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:4096'], 'projectId' => ['nullable', 'ulid'], 'residentId' => ['nullable', 'ulid'], 'requirementId' => ['nullable', 'ulid']]);
        abort_unless(array_diff(array_keys($request->query()), array_keys($filters)) === [], 422);

        return $this->response($this->workflow->read($request->route('operation'), $request->route('record'), $filters, (string) $request->user()->getAuthIdentifier()));
    }

    public function write(EvidenceRequest $request): JsonResponse
    {
        $new = $request->isMethod('POST');
        $version = null;
        $key = $request->header('Idempotency-Key');
        if ($new) {
            abort_unless(is_string($key) && (bool) preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key), 422);
        } else {
            abort_unless((bool) preg_match('/^"([1-9][0-9]{0,9})"$/D', $request->header('If-Match', ''), $matches), 422);
            $version = (int) $matches[1];
            $key = null;
        }
        $actor = (string) $request->user()->getAuthIdentifier();
        $correlation = (string) $request->attributes->get('correlationId');
        try {
            if ($request->route('operation') === 'stage') {
                $file = $request->file('file');
                abort_unless($file instanceof UploadedFile, 422);
                $result = $this->workflow->stage($file->getPathname(), $file->getClientOriginalName(), $request->safe()->except(['file', 'purpose']), (string) $key, $actor, $correlation);
            } else {
                $result = $this->workflow->execute($request->route('operation'), $request->route('record'), $request->validated(), $version, $key, $actor, $correlation);
            }
        } catch (InvalidArgumentException) {
            abort(422);
        }

        return $this->response($result, $new ? 201 : 200);
    }

    public function download(Request $request): Response
    {
        $bytes = $this->workflow->download((string) $request->route('record'), (string) $request->user()->getAuthIdentifier());

        return response($bytes['content'])->withHeaders(['Content-Type' => $bytes['mime'], 'Content-Disposition' => 'attachment; filename="'.$bytes['name'].'"',
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'"]);
    }

    /** @param array<string, mixed> $data */
    private function response(array $data, int $status = 200): JsonResponse
    {
        $response = (new ResidencyResource(['data' => $data]))->response()->setStatusCode($status)->header('Cache-Control', 'no-store, private');
        if (isset($data['version'])) {
            $response->header('ETag', '"'.$data['version'].'"');
        }

        return $response;
    }
}
