<?php

namespace App\Modules\LaboratoryRequests\Presentation\Http\Controllers\Api\V1;

use App\Modules\LaboratoryRequests\Application\DecisionEffects;
use App\Modules\LaboratoryRequests\Application\RequestCapture;
use App\Modules\LaboratoryRequests\Application\RequestDecisions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LaboratoryDecisionController extends LaboratoryRequestController
{
    public function __construct(RequestCapture $capture, private RequestDecisions $decisions, private DecisionEffects $effects)
    {
        parent::__construct($capture);
    }

    public function queue(Request $request): JsonResponse
    {
        $input = $this->input($request, ['cursor' => ['sometimes', 'string', 'max:2048']]);

        return $this->respond($this->decisions->queue($this->actor($request), $input['cursor'] ?? null));
    }

    public function workflow(Request $request): JsonResponse
    {
        $id = $request->route('record');
        $input = $this->input($request, $id === null ? ['cursor' => ['sometimes', 'string', 'max:2048']] : []);

        return $this->respond($this->decisions->workflow($this->actor($request), $id, $input['cursor'] ?? null));
    }

    public function calendar(Request $request): JsonResponse
    {
        $input = $this->input($request, ['laboratoryId' => ['required', 'ulid'], 'startAt' => ['required', 'string', 'max:40'], 'endAt' => ['required', 'string', 'max:40']]);

        return $this->respond($this->decisions->calendar($input['laboratoryId'], $input['startAt'], $input['endAt']));
    }

    public function decide(Request $request): JsonResponse
    {
        $input = $this->input($request, ['decision' => ['required', 'in:AUTORIZADO,RECHAZADO'], 'reason' => ['required', 'string', 'min:3', 'max:2000']]);

        return $this->respond($this->decisions->decide($this->actor($request), $request->route('record'), $input,
            $this->version($request), $this->key($request), $this->correlation($request)));
    }

    public function cancel(Request $request): JsonResponse
    {
        $input = $this->input($request, ['reason' => ['required', 'string', 'min:3', 'max:2000']]);

        return $this->respond($this->decisions->cancel($this->actor($request), $request->route('record'), $input['reason'],
            $this->version($request), $this->key($request), $this->correlation($request)));
    }

    public function history(Request $request): JsonResponse
    {
        $this->input($request, []);

        return $this->respond($this->decisions->history($this->actor($request), $request->route('record')));
    }

    public function notifications(Request $request): JsonResponse
    {
        $input = $this->input($request, ['cursor' => ['sometimes', 'string', 'max:2048']]);

        return $this->respond($this->effects->notifications($this->actor($request), $input['cursor'] ?? null));
    }

    public function receipt(Request $request): JsonResponse
    {
        $this->input($request, []);

        return $this->respond($this->effects->receipt($this->actor($request), $request->route('record')));
    }

    public function receiptFile(Request $request): Response
    {
        $this->input($request, []);
        $file = $this->effects->receipt($this->actor($request), $request->route('record'), true);

        return response($file['content'])->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="'.$file['name'].'"')
            ->header('X-Content-Type-Options', 'nosniff')->header('Cache-Control', 'no-store, private');
    }

    /** @param array<string, mixed> $rules
     * @return array<string, mixed> */
    private function input(Request $request, array $rules): array
    {
        $validated = $request->validate($rules);
        abort_if(array_diff_key($request->all(), $validated) !== [], 422);

        return $validated;
    }
}
