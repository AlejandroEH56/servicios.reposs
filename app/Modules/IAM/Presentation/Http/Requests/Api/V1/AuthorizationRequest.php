<?php

namespace App\Modules\IAM\Presentation\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class AuthorizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('iam.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return match ($this->route()->getName()) {
            'iam.roles.create' => ['code' => ['required', 'string', 'max:100', 'regex:/^[A-Z][A-Z0-9_]*$/'], 'name' => ['required', 'string', 'max:160']],
            'iam.permissions.replace' => [
                'expectedVersion' => ['required', 'integer', 'min:1'],
                'permissions' => ['present', 'array', 'max:100'],
                'permissions.*.permissionId' => ['required', 'ulid', 'distinct'],
                'permissions.*.scope' => ['required', 'in:SELF,GLOBAL'],
            ],
            default => [
                'expectedAuthorizationVersion' => ['required', 'integer', 'min:1'],
                'expiresAt' => ['nullable', 'date', 'after:now'],
            ],
        };
    }
}
