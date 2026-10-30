<?php

namespace App\Modules\Residencies\Application;

interface ProgressWorkflow
{
    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed> */
    public function read(string $operation, ?string $resource, array $filters, string $actor): array;

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function execute(string $operation, string $resource, array $input, string $key, string $actor, string $correlation): array;
}
