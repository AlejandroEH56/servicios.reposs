<?php

namespace App\Modules\LaboratoryRequests\Application;

interface RequestDecisions
{
    /** @return array<string, mixed> */
    public function workflow(string $actor, ?string $id = null, ?string $cursor = null): array;

    /** @return array<string, mixed> */
    public function queue(string $actor, ?string $cursor = null): array;

    /** @param array{decision: string, reason: string} $input
     * @return array<string, mixed> */
    public function decide(string $actor, string $id, array $input, int $version, string $key, string $correlation): array;

    /** @return array<string, mixed> */
    public function cancel(string $actor, string $id, string $reason, int $version, string $key, string $correlation): array;

    /** @return array<string, mixed> */
    public function calendar(string $laboratory, string $start, string $end): array;

    /** @return array<string, mixed> */
    public function history(string $actor, string $id): array;
}
