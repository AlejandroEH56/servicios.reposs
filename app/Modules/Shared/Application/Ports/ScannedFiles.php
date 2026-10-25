<?php

namespace App\Modules\Shared\Application\Ports;

interface ScannedFiles
{
    public function store(string $source, string $actor): string;

    /** @return array{content: string, mime: string} */
    public function bytes(string $id): array;

    /** Internal metadata port; callers authorize the owning resource.
     * @return array{id: string, ownerId: string, status: string, mime: string, size: int, sha256: string}|null */
    public function metadata(string $id): ?array;
}
