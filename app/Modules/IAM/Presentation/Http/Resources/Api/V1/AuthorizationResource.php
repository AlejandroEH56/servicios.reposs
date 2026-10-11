<?php

namespace App\Modules\IAM\Presentation\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthorizationResource extends JsonResource
{
    /** @return array<string, mixed>|list<array<string, mixed>> */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
