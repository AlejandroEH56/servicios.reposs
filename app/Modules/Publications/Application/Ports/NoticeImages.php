<?php

namespace App\Modules\Publications\Application\Ports;

interface NoticeImages
{
    /** @return array<string, mixed> */
    public function stage(string $path, string $actor, string $key, string $correlation): array;

    /** @return array{content: string, mime: string} */
    public function download(string $file, ?string $notice, ?string $actor): array;
}
