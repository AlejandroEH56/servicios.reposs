<?php

namespace App\Modules\LaboratoryRequests\Application;

interface DecisionEffects
{
    /** @param array<string, mixed> $event */
    public function deliver(array $event): void;

    /** @return array<string, mixed> */
    public function receipt(string $actor, string $request, bool $download = false): array;

    /** @return array<string, mixed> */
    public function notifications(string $actor, ?string $cursor = null): array;
}
