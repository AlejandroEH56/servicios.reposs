<?php

namespace App\Modules\Residencies\Application\Ports;

interface DocumentRenderer
{
    /** @param array<string,mixed> $snapshot */
    public function html(array $snapshot): string;

    public function pdf(string $html): string;
}
