<?php

namespace App\Modules\Shared\Application\Ports;

interface ScannedFiles
{
    public function store(string $source, string $actor): string;

    /** @return array{content: string, mime: string} */
    public function bytes(string $id): array;
}
