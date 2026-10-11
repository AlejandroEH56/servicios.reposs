<?php

namespace App\Modules\IAM\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\IAM\Application\AuthorizationAdministration;
use App\Modules\IAM\Presentation\Http\Requests\Api\V1\AuthorizationRequest;
use App\Modules\IAM\Presentation\Http\Resources\Api\V1\AuthorizationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AuthorizationController extends Controller
{
    public function __construct(private AuthorizationAdministration $administration) {}

    public function read(Request $request): JsonResponse
    {
        Gate::authorize('iam.read');
        $request->validate(['q' => ['sometimes', 'string', 'max:100']]);
        $data = match ($request->route()->getName()) {
            'iam.roles' => $this->administration->roles(),
            'iam.permissions' => $this->administration->permissions(),
            'iam.identities' => $this->administration->identities((string) $request->query('q', '')),
            default => $this->administration->assignments($request->route('identity')),
        };

        return (new AuthorizationResource($data))->response()->header('Cache-Control', 'no-store, private');
    }

    public function write(AuthorizationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $actor = $request->user()->getAuthIdentifier();
        $correlation = $request->attributes->get('correlationId');
        $result = match ($request->route()->getName()) {
            'iam.roles.create' => $this->administration->createRole($data, $actor, $correlation),
            'iam.permissions.replace' => $this->administration->replacePermissions($request->route('role'), $data['expectedVersion'], $data['permissions'], $actor, $correlation),
            default => $this->assignment($request, $data, $actor, $correlation),
        };

        return (new AuthorizationResource($result))->response()->setStatusCode($request->route()->getName() === 'iam.roles.create' ? 201 : 200)
            ->header('Cache-Control', 'no-store, private');
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    private function assignment(Request $request, array $data, string $actor, string $correlation): array
    {
        $this->administration->changeAssignment($request->route('identity'), $request->route('role'), $data['expectedAuthorizationVersion'],
            $request->isMethod('POST'), $data['expiresAt'] ?? null, $actor, $correlation);

        return $this->administration->assignments($request->route('identity'));
    }
}
