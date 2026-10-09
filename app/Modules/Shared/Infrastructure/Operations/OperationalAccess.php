<?php

namespace App\Modules\Shared\Infrastructure\Operations;

use RuntimeException;

class OperationalAccess
{
    public function owner(): string
    {
        $owner = config('modernization.operations.owner');
        if (! app()->runningInConsole() || ! config('modernization.operations.enabled')
            || ! is_string($owner) || $owner === '') {
            throw new RuntimeException('Restricted operator environment required.');
        }

        return $owner;
    }
}
