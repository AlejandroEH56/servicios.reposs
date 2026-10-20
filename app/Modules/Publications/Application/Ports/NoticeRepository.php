<?php

namespace App\Modules\Publications\Application\Ports;

use App\Modules\Publications\Application\DTO\NoticeInput;

interface NoticeRepository
{
    /** @param array<string, mixed> $query
     * @return array<string, mixed> */
    public function page(bool $public, array $query): array;

    /** @return array<string, mixed> */
    public function get(string $id, bool $public): array;

    /** @return array<string, mixed> */
    public function command(string $action, ?string $id, ?NoticeInput $input, ?int $version, ?string $key, string $actor, string $correlation): array;
}
