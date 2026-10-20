<?php

namespace App\Modules\Publications\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Publications\Application\DTO\NoticeInput;
use App\Modules\Publications\Application\NoticeService;
use App\Modules\Publications\Application\Ports\NoticeImages;
use App\Modules\Publications\Presentation\Http\Requests\Api\V1\NoticeRequest;
use App\Modules\Publications\Presentation\Http\Resources\Api\V1\NoticeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

class NoticeController extends Controller
{
    public function __construct(private NoticeService $notices, private NoticeImages $images) {}

    public function read(Request $request): JsonResponse
    {
        $public = (bool) $request->route('public');
        abort_if($public && ! config('publications.public_enabled'), 404);
        $actor = $request->user()?->getAuthIdentifier();
        if ($request->route('notice') !== null) {
            $data = ['data' => $this->notices->get($request->route('notice'), $public, $actor)];
        } else {
            $query = $request->validate(['page' => ['sometimes', 'array:size,after'], 'page.size' => ['sometimes', 'integer', 'min:1', 'max:100'],
                'page.after' => ['sometimes', 'string', 'max:1024'], 'filter' => ['sometimes', $public ? 'array:type' : 'array:type,status'],
                'filter.type' => ['sometimes', 'in:RESIDENCIAS,SERVICIO_SOCIAL,EMPLEO'], 'filter.status' => [$public ? 'prohibited' : 'sometimes', 'in:BORRADOR,PUBLICADO,RETIRADO'],
                'q' => ['nullable', 'string', 'max:100']]);
            abort_unless(count(array_diff(array_keys($request->query()), ['page', 'filter', 'q'])) === 0, 422);
            $data = $this->notices->page($public, $query, $actor);
        }

        return $this->response($data);
    }

    public function write(NoticeRequest $request): JsonResponse
    {
        $operation = $request->route('operation');
        $key = $request->header('Idempotency-Key');
        if ($operation !== 'update') {
            abort_unless(is_string($key) && preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key), 422);
        }
        $version = null;
        if (in_array($operation, ['update', 'publish', 'withdraw'], true)) {
            $matched = preg_match('/^"([1-9][0-9]{0,9})"$/D', $request->header('If-Match', ''), $matches);
            abort_unless($matched === 1, 422);
            $version = (int) $matches[1];
        }
        $actor = (string) $request->user()->getAuthIdentifier();
        $correlation = (string) $request->attributes->get('correlationId');
        try {
            if ($operation === 'stage') {
                $data = $this->images->stage($request->file('file')->getRealPath(), $actor, $key, $correlation);
            } else {
                $values = $request->validated();
                $input = in_array($operation, ['create', 'update'], true) ? new NoticeInput($values['type'], $values['title'], $values['description'],
                    $values['requirements'] ?? '', $values['url'] ?? null, $values['publicationWindow']['startsAt'] ?? null,
                    $values['publicationWindow']['endsAt'] ?? null, $values['imageId'] ?? null) : null;
                $data = $this->notices->command($operation, $request->route('notice'), $input, $version, $key, $actor, $correlation);
            }
        } catch (InvalidArgumentException) {
            abort(422);
        }

        return $this->response(['data' => $data], in_array($operation, ['create', 'stage'], true) ? 201 : 200);
    }

    public function download(Request $request): Response
    {
        $bytes = $this->images->download($request->route('file'), $request->route('notice'), $request->user()?->getAuthIdentifier());

        return response($bytes['content'])->withHeaders(['Content-Type' => $bytes['mime'], 'Content-Disposition' => 'inline; filename="notice.png"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, max-age=0', 'Cross-Origin-Resource-Policy' => 'same-origin']);
    }

    /** @param array<string, mixed> $data */
    private function response(array $data, int $status = 200): JsonResponse
    {
        $response = (new NoticeResource($data))->response()->setStatusCode($status)->header('Cache-Control', 'no-store, max-age=0');
        if (isset($data['data']['version'])) {
            $response->header('ETag', '"'.$data['data']['version'].'"');
        }

        return $response;
    }
}
