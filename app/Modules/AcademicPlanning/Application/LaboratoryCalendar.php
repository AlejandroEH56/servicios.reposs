<?php

namespace App\Modules\AcademicPlanning\Application;

interface LaboratoryCalendar
{
    /** @param array<string, mixed> $filters
     * @return array<string, mixed> */
    public function schedules(string $laboratory, array $filters): array;

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public function publish(string $laboratory, ?string $schedule, array $input, ?int $version, ?string $key, string $actor, string $correlation): array;

    /** @return array<string, mixed> */
    public function availability(string $laboratory, string $start, string $end): array;
}
