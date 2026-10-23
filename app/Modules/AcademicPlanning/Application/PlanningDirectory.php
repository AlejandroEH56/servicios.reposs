<?php

namespace App\Modules\AcademicPlanning\Application;

interface PlanningDirectory
{
    /** @param array<string, mixed> $filters
     * @return array<string, mixed> */
    public function read(string $kind, ?string $id, array $filters): array;

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    public function execute(string $kind, string $action, ?string $id, array $data, ?int $version, ?string $key, string $actor, string $correlation): array;
}
