<?php

namespace App\Modules\Organization\Application;

interface ProfileProjector
{
    /** @param array<string, mixed> $event */
    public function handle(array $event): void;

    public function synchronize(string $identityId): void;
}
