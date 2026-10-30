<?php

namespace App\Modules\Residencies\Application;

interface DocumentWorkflow
{
    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed> */
    public function read(string $operation, ?string $resource, array $filters, string $actor): array;

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function execute(string $operation, ?string $resource, array $input, string $key, string $actor, string $correlation): array;

    /**
     * @return array<string,mixed> */
    public function stageResource(string $path, string $key, string $actor, string $correlation): array;

    /**
     * @return array{content:string,mime:string,name:string} */
    public function download(string $id, string $actor): array;

    public function render(string $id): void;

    public function queueRender(string $id): void;

    public function invalidateForProject(string $project, string $reason, string $actor, string $correlation): void;
}
