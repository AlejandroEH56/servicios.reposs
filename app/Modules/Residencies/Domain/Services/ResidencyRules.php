<?php

namespace App\Modules\Residencies\Domain\Services;

use DateTimeImmutable;
use InvalidArgumentException;

final class ResidencyRules
{
    public static function dates(string $start, string $end): void
    {
        foreach ([$start, $end] as $value) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($date === false || $date->format('Y-m-d') !== $value) {
                throw new InvalidArgumentException('Invalid calendar date.');
            }
        }
        if ($end < $start) {
            throw new InvalidArgumentException('End date precedes start date.');
        }
    }

    public static function contained(string $start, string $end, string $lower, string $upper): void
    {
        self::dates($start, $end);
        self::dates($lower, $upper);
        if ($start < $lower || $end > $upper) {
            throw new InvalidArgumentException('Dates must be inside the parent period.');
        }
    }

    public static function overlaps(string $start, string $end, string $otherStart, string $otherEnd): bool
    {
        self::dates($start, $end);
        self::dates($otherStart, $otherEnd);

        return $start <= $otherEnd && $end >= $otherStart;
    }

    public static function partialReports(int $months): int
    {
        return match ($months) {
            4 => 2,
            5, 6 => 3,
            default => throw new InvalidArgumentException('Period duration must be 4, 5 or 6 months.'),
        };
    }

    public static function rfc(string $value): string
    {
        $value = mb_strtoupper(trim($value), 'UTF-8');
        if (! preg_match('/^[A-ZÑ&]{3,4}[0-9]{2}(?:0[1-9]|1[0-2])(?:0[1-9]|[12][0-9]|3[01])[A-Z0-9]{2}[A0-9]$/uD', $value)) {
            throw new InvalidArgumentException('Invalid RFC format.');
        }
        if (in_array($value, ['XAXX010101000', 'XEXX010101000'], true)) {
            return $value;
        }
        $body = mb_substr($value, 0, -1, 'UTF-8');
        $length = mb_strlen($body, 'UTF-8');
        $alphabet = '0123456789ABCDEFGHIJKLMN&OPQRSTUVWXYZ Ñ';
        $sum = $length === 12 ? 0 : 481;
        for ($i = 0; $i < $length; $i++) {
            $position = mb_strpos($alphabet, mb_substr($body, $i, 1, 'UTF-8'), encoding: 'UTF-8');
            if ($position === false) {
                throw new InvalidArgumentException('Invalid RFC character.');
            }
            $sum += $position * ($length + 1 - $i);
        }
        $digit = 11 - $sum % 11;
        $expected = match ($digit) {
            11 => '0', 10 => 'A', default => (string) $digit
        };
        if (mb_substr($value, -1, 1, 'UTF-8') !== $expected) {
            throw new InvalidArgumentException('Invalid RFC check digit.');
        }

        return $value;
    }
}
