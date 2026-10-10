<?php

namespace App\Modules\IAM\Application;

interface AuthorizationAdministration
{
    /** @return list<array<string, mixed>> */
    public function roles(): array;

    /** @return list<array<string, mixed>> */
    public function permissions(): array;

    /** @return list<array<string, mixed>> */
    public function identities(string $search): array;

    /** @return array<string, mixed> */
    public function assignments(string $identityId): array;

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    public function createRole(array $data, string $actor, string $correlation): array;

    /** @param list<array{permissionId: string, scope: string}> $permissions
     * @return array<string, mixed> */
    public function replacePermissions(string $roleId, int $version, array $permissions, string $actor, string $correlation): array;

    public function changeAssignment(string $identityId, string $roleId, int $version, bool $grant, ?string $expiresAt, string $actor, string $correlation): void;

    public function bootstrap(string $identityId, string $operator): void;
}
