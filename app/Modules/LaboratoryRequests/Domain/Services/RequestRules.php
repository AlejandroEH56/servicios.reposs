<?php

namespace App\Modules\LaboratoryRequests\Domain\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class RequestRules
{
    public const DETAILS = ['PRACTICA' => 'practice', 'VARIA' => 'various', 'EXTRAORDINARIA' => 'extraordinary'];

    /** @return array{DateTimeImmutable, DateTimeImmutable} */
    public static function window(string $start, string $end, DateTimeImmutable $now): array
    {
        $from = self::instant($start);
        $until = self::instant($end);
        $zone = new DateTimeZone('America/Mexico_City');
        $localStart = $from->setTimezone($zone);
        $localEnd = $until->setTimezone($zone);

        if ($from <= $now || $until <= $from
            || $localStart->format('Y-m-d') !== $localEnd->format('Y-m-d')
            || $localStart->format('H:i:s.u') < '07:00:00.000000'
            || $localEnd->format('H:i:s.u') > '20:00:00.000000') {
            throw new InvalidArgumentException('Expected a future, same-day interval between 07:00 and 20:00.');
        }

        return [$from, $until];
    }

    private static function instant(string $value): DateTimeImmutable
    {
        if (! preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00))$/D', $value)) {
            throw new InvalidArgumentException('Expected a date and time with UTC offset.');
        }
        $instant = new DateTimeImmutable($value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false) {
            throw new InvalidArgumentException('Invalid date and time.');
        }

        return $instant->setTimezone(new DateTimeZone('UTC'));
    }
}
