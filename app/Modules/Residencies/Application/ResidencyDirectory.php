<?php

namespace App\Modules\Residencies\Application;

interface ResidencyDirectory
{
    /** @param array<string, mixed> $filters
     * @return array<string, mixed> */
    public function read(string $kind, ?string $record, ?string $parent, array $filters, string $actor): array;

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public function execute(string $kind, ?string $record, ?string $parent, array $input, ?int $version, ?string $key, string $actor, string $correlation): array;
}
