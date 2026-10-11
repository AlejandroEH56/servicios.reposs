<?php

namespace App\Modules\Organization\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\IAM\Application\AuthorizationDirectory;
use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Organization\Presentation\Http\Requests\Api\V1\OrganizationRequest;
use App\Modules\Organization\Presentation\Http\Resources\Api\V1\OrganizationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrganizationController extends Controller
{
    public function __construct(private OrganizationDirectory $directory, private AuthorizationDirectory $authorization) {}

    public function read(Request $request): JsonResponse
    {
        $request->validate(['q' => ['sometimes', 'string', 'max:100']]);
        $search = (string) $request->query('q', '');
        $data = match ($request->route('operation')) {
            'employees' => $this->employees($request, $search),
            'employee' => $this->employee($request->route('employee')),
            default => $this->reference($request->route('operation'), $search),
        };

        return (new OrganizationResource($data))->response()->header('Cache-Control', 'no-store, private');
    }

    /** @return list<array<string, mixed>> */
    private function employees(Request $request, string $search): array
    {
        $actor = $request->user()->getAuthIdentifier();
        $global = $this->authorization->allows($actor, 'organization.empleados.consultar');
        abort_unless($global || $this->authorization->allows($actor, 'organization.empleados.consultar', $actor), 403);

        return $this->directory->employees($global ? null : $actor, $search);
    }

    /** @return array<string, mixed> */
    private function employee(string $id): array
    {
        $employee = $this->directory->employee($id);
        Gate::authorize('organization.employee.view', [$employee]);

        return $employee;
    }

    /** @return list<array<string, mixed>> */
    private function reference(string $operation, string $search): array
    {
        $permission = match ($operation) {
            'units' => 'organization.unidades.consultar',
            'levels' => 'organization.niveles.consultar',
            'candidates' => 'organization.empleados.gestionar',
            default => 'organization.asesores.consultar',
        };
        Gate::authorize('organization.capability', [$permission]);

        return match ($operation) {
            'units' => $this->directory->units(),
            'levels' => $this->directory->levels(),
            'candidates' => $this->directory->identityCandidates($search),
            default => $this->directory->advisers($search),
        };
    }

    public function write(OrganizationRequest $request): JsonResponse
    {
        $action = $request->route('operation');
        $id = $request->route('employee') ?? $request->route('unit') ?? $request->route('level');
        $data = $this->directory->execute($action, $id, $request->validated(), $request->user()->getAuthIdentifier(), $request->attributes->get('correlationId'));

        return (new OrganizationResource($data))->response()->setStatusCode(str_starts_with($action, 'create') ? 201 : 200)
            ->header('Cache-Control', 'no-store, private');
    }
}
