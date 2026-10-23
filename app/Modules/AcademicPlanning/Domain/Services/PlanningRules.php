<?php

namespace App\Modules\AcademicPlanning\Domain\Services;

use DateTimeImmutable;
use InvalidArgumentException;

final class PlanningRules
{
    public static function text(string $value): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/[<>\x00-\x1F\x7F]/u', $value)) {
            throw new InvalidArgumentException('Planning text must be plain and nonempty.');
        }

        return $value;
    }

    public static function dates(string $start, string $end): void
    {
        foreach ([$start, $end] as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('Invalid calendar date.');
            }
        }
        if ($end <= $start) {
            throw new InvalidArgumentException('Semester end must follow its start.');
        }
    }

    public static function instant(string $value): string
    {
        if (! preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d:\d\d)$/D', $value)) {
            throw new InvalidArgumentException('RFC3339 timestamp required.');
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (\Exception) {
            throw new InvalidArgumentException('Invalid timestamp.');
        }
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new InvalidArgumentException('Invalid timestamp.');
        }
        if (preg_match('/([+-])(\d\d):(\d\d)$/', $value, $offset) && ((int) $offset[2] > 14 || (int) $offset[3] > 59 || ((int) $offset[2] === 14 && (int) $offset[3] !== 0))) {
            throw new InvalidArgumentException('Invalid UTC offset.');
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    public static function transition(string $status, string $action): string
    {
        return match ([$status, $action]) {
            ['PLANIFICADO', 'activate'] => 'ACTIVO',
            ['ACTIVO', 'close'] => 'CERRADO',
            ['PLANIFICADO', 'archive'], ['CERRADO', 'archive'] => 'ARCHIVADO',
            default => throw new InvalidArgumentException('Invalid semester transition.'),
        };
    }
}
