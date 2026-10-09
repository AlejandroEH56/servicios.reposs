<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class IdentityResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getAuthIdentifier(), 'displayName' => $this->resource->name,
            'email' => $this->resource->email, 'status' => $this->resource->estado->value,
            'roles' => [], 'permissions' => [], 'scopes' => [],
            'sessionExpiresAt' => Carbon::createFromTimestamp(min(
                $request->session()->get('iam_authenticated_at') + config('modernization.sessions.absolute_seconds'),
                $request->session()->get('iam_authenticated_at') + config('modernization.sessions.group_freshness_seconds'),
                now()->timestamp + min(config('modernization.sessions.idle_seconds'), config('session.lifetime') * 60)
            ))->toISOString(),
        ];
    }
}
