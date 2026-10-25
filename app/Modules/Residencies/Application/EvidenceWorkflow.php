<?php

namespace App\Modules\Residencies\Application;

interface EvidenceWorkflow
{
    /** @param array<string, mixed> $filters
     * @return array<string, mixed> */
    public function read(string $operation, ?string $resource, array $filters, string $actor): array;

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public function execute(string $operation, ?string $resource, array $input, ?int $version, ?string $key, string $actor, string $correlation): array;

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public function stage(string $path, string $name, array $input, string $key, string $actor, string $correlation): array;

    /** @return array{content: string, mime: string, name: string} */
    public function download(string $evidence, string $actor): array;
}
