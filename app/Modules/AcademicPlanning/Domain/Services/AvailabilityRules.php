<?php

namespace App\Modules\AcademicPlanning\Domain\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class AvailabilityRules
{
    public const TIME_ZONE = 'America/Mexico_City';

    public static function slot(int $weekday, string $start, string $end): void
    {
        if ($weekday < 1 || $weekday > 7 || ! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $start)
            || ! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $end)
            || $start < '07:00' || $end > '20:00' || $end <= $start) {
            throw new InvalidArgumentException('A positive weekly slot within 07:00–20:00 is required.');
        }
    }

    /** @return array{0: DateTimeImmutable, 1: DateTimeImmutable} */
    public static function window(string $start, string $end): array
    {
        $utc = new DateTimeZone('UTC');
        $from = new DateTimeImmutable(PlanningRules::instant($start), $utc);
        $until = new DateTimeImmutable(PlanningRules::instant($end), $utc);
        if ($until <= $from || self::micros($until) - self::micros($from) > 31 * 86400 * 1000000) {
            throw new InvalidArgumentException('A positive window of at most 31 days is required.');
        }

        return [$from, $until];
    }

    /**
     * @param  list<array{weekday: int, startsAtLocal: string, endsAtLocal: string}>  $slots
     * @param  list<array{startAt: string, endAt: string}>  $holidays
     * @return list<array{startAt: string, endAt: string}>
     */
    public static function calculate(DateTimeImmutable $from, DateTimeImmutable $until, string $semesterStart, string $semesterEnd, array $slots, array $holidays): array
    {
        PlanningRules::dates($semesterStart, $semesterEnd);
        $zone = new DateTimeZone(self::TIME_ZONE);
        $utc = new DateTimeZone('UTC');
        $lower = max(self::micros($from), self::micros(new DateTimeImmutable($semesterStart, $zone)));
        $upper = min(self::micros($until), self::micros((new DateTimeImmutable($semesterEnd, $zone))->modify('+1 day')));
        foreach ($slots as $slot) {
            self::slot($slot['weekday'], $slot['startsAtLocal'], $slot['endsAtLocal']);
        }
        $open = [];
        for ($day = $from->setTimezone($zone)->setTime(0, 0); $day < $until; $day = $day->modify('+1 day')) {
            foreach ($slots as $slot) {
                if ((int) $day->format('N') !== $slot['weekday']) {
                    continue;
                }
                $start = max($lower, self::micros(new DateTimeImmutable($day->format('Y-m-d').' '.$slot['startsAtLocal'], $zone)));
                $end = min($upper, self::micros(new DateTimeImmutable($day->format('Y-m-d').' '.$slot['endsAtLocal'], $zone)));
                if ($start < $end) {
                    $open[] = [$start, $end];
                }
            }
        }
        $blocks = [];
        foreach ($holidays as $holiday) {
            $start = self::micros(new DateTimeImmutable($holiday['startAt'], $utc));
            $end = self::micros(new DateTimeImmutable($holiday['endAt'], $utc));
            if ($end <= $start) {
                throw new InvalidArgumentException('Invalid stored holiday interval.');
            }
            $blocks[] = [$start, $end];
        }
        $blocks = self::merge($blocks);
        $available = [];
        $index = 0;
        foreach (self::merge($open) as [$start, $end]) {
            while (isset($blocks[$index]) && $blocks[$index][1] <= $start) {
                $index++;
            }
            $cursor = $start;
            for ($i = $index; isset($blocks[$i]) && $blocks[$i][0] < $end; $i++) {
                if ($blocks[$i][0] > $cursor) {
                    $available[] = [$cursor, min($end, $blocks[$i][0])];
                }
                $cursor = max($cursor, $blocks[$i][1]);
                if ($cursor >= $end) {
                    break;
                }
            }
            if ($cursor < $end) {
                $available[] = [$cursor, $end];
            }
        }

        return array_map(fn (array $range): array => ['startAt' => self::format($range[0]), 'endAt' => self::format($range[1])], $available);
    }

    public static function iso(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function micros(DateTimeImmutable $date): int
    {
        return (int) $date->format('U') * 1000000 + (int) $date->format('u');
    }

    private static function format(int $micros): string
    {
        $seconds = intdiv($micros, 1000000);
        $fraction = $micros % 1000000;
        if ($fraction < 0) {
            $seconds--;
            $fraction += 1000000;
        }
        $date = DateTimeImmutable::createFromFormat('U.u', $seconds.'.'.str_pad((string) $fraction, 6, '0', STR_PAD_LEFT));
        if ($date === false) {
            throw new InvalidArgumentException('Invalid instant.');
        }

        return self::iso($date);
    }

    /** @param list<array{0: int, 1: int}> $ranges
     * @return list<array{0: int, 1: int}> */
    private static function merge(array $ranges): array
    {
        usort($ranges, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $result = [];
        foreach ($ranges as [$start, $end]) {
            $last = count($result) - 1;
            if ($last >= 0 && $result[$last][1] >= $start) {
                $result[$last][1] = max($result[$last][1], $end);
            } else {
                $result[] = [$start, $end];
            }
        }

        return $result;
    }
}
