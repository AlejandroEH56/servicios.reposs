<?php

namespace App\Modules\IAM\Application;

interface IdentityDirectory
{
    /** @return list<array{id: string, displayName: string, status: string}> */
    public function search(string $search): array;

    /** @return array{id: string, displayName: string, email: ?string, status: string, jobTitle: ?string, profileVersion: int}|null */
    public function find(string $identityId): ?array;
}
