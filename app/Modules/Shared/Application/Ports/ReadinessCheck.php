<?php

namespace App\Modules\Shared\Application\Ports;

interface ReadinessCheck
{
    public function isReady(): bool;
}
