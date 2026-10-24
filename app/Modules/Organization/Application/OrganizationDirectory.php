<?php

namespace App\Modules\Organization\Application;

interface OrganizationDirectory
{
    /** @return list<array{id: string, displayName: string}> */
    public function responsibleCandidates(string $search): array;

    /** @return list<array{id: string, displayName: string, status: string}> */
    public function identityCandidates(string $search): array;

    /** @return list<array<string, mixed>> */
    public function employees(?string $owner, string $search): array;

    /** @return array<string, mixed> */
    public function employee(string $employeeId): array;

    /** @return list<array<string, mixed>> */
    public function advisers(string $search): array;

    /** @return list<array<string, mixed>> */
    public function units(): array;

    /** @return list<array<string, mixed>> */
    public function levels(): array;

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    public function execute(string $action, ?string $resourceId, array $data, string $actor, string $correlation): array;
}
