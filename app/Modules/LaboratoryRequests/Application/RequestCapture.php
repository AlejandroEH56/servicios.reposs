<?php

namespace App\Modules\LaboratoryRequests\Application;

interface RequestCapture
{
    /** @return array<string, mixed> */
    public function quote(string $laboratory, string $start, string $end): array;

    /** @return array<string, mixed> */
    public function read(string $actor, ?string $id, ?string $cursor = null): array;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(string $actor, ?string $id, array $input, ?int $version, ?string $key, string $correlation): array;

    /** @return array<string, mixed> */
    public function submit(string $actor, string $id, int $version, string $key, string $correlation): array;
}
