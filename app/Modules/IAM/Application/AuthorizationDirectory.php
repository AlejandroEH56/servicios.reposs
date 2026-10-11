<?php

namespace App\Modules\IAM\Application;

interface AuthorizationDirectory
{
    /** @return array{roles: list<string>, permissions: list<string>, scopes: list<string>} */
    public function effective(string $identityId): array;

    public function allows(string $identityId, string $permission, ?string $resourceOwner = null): bool;
}
