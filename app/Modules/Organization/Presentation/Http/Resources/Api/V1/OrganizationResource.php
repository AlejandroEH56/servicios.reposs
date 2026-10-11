<?php

namespace App\Modules\Organization\Presentation\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    /** @return array<string, mixed>|list<array<string, mixed>> */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
