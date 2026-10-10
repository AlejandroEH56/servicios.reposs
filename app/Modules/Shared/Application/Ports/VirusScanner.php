<?php

namespace App\Modules\Shared\Application\Ports;

interface VirusScanner
{
    public function isClean(string $path): bool;
}
