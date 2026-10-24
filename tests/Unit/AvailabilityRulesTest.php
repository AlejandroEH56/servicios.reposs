<?php

namespace Tests\Unit;

use App\Modules\AcademicPlanning\Domain\Services\AvailabilityRules;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AvailabilityRulesTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function goldenCases(): iterable
    {
        $cases = json_decode(file_get_contents(__DIR__.'/availability-golden.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('goldenCases')]
    public function test_golden_master_of_calendar_availability(array $case): void
    {
        [$from, $until] = AvailabilityRules::window($case['start'], $case['end']);
        $actual = AvailabilityRules::calculate($from, $until, $case['semesterStart'] ?? '2026-01-01', $case['semesterEnd'] ?? '2026-12-31',
            array_map(fn (array $slot): array => ['weekday' => $slot[0], 'startsAtLocal' => $slot[1], 'endsAtLocal' => $slot[2]], $case['slots']),
            array_map(fn (array $block): array => ['startAt' => $block[0], 'endAt' => $block[1]], $case['holidays'] ?? []));
        $this->assertSame(array_map(fn (array $range): array => ['startAt' => $range[0], 'endAt' => $range[1]], $case['expected']), $actual);
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function invalidSlots(): iterable
    {
        yield 'before opening' => [1, '06:59', '08:00'];
        yield 'after closing' => [1, '19:00', '20:01'];
        yield 'empty interval' => [1, '07:00', '07:00'];
        yield 'overnight' => [1, '19:00', '08:00'];
        yield 'invalid minute' => [1, '07:99', '09:00'];
        yield 'zero weekday' => [0, '07:00', '08:00'];
        yield 'invalid weekday' => [8, '07:00', '08:00'];
        yield 'seconds disallowed' => [1, '07:00:00', '08:00'];
    }

    #[DataProvider('invalidSlots')]
    public function test_legacy_operating_bounds_and_weekly_slot_validation(int $day, string $start, string $end): void
    {
        $this->expectException(InvalidArgumentException::class);
        AvailabilityRules::slot($day, $start, $end);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidWindows(): iterable
    {
        yield 'missing offset' => ['2026-10-19T13:00:00', '2026-10-19T14:00:00Z'];
        yield 'nonexistent date' => ['2026-02-30T13:00:00Z', '2026-03-03T14:00:00Z'];
        yield 'invalid offset' => ['2026-10-19T13:00:00+14:01', '2026-10-19T14:00:00Z'];
        yield 'reversed window' => ['2026-10-19T15:00:00Z', '2026-10-19T14:00:00Z'];
        yield 'empty window' => ['2026-10-19T13:00:00Z', '2026-10-19T13:00:00Z'];
        yield 'excessive range' => ['2026-10-01T13:00:00Z', '2026-11-01T13:00:00.000001Z'];
    }

    #[DataProvider('invalidWindows')]
    public function test_queries_reject_ambiguous_or_unbounded_intervals(string $start, string $end): void
    {
        $this->expectException(InvalidArgumentException::class);
        AvailabilityRules::window($start, $end);
    }

    public function test_thirty_one_days_is_the_exact_maximum(): void
    {
        [$from, $until] = AvailabilityRules::window('2026-10-01T13:00:00Z', '2026-11-01T13:00:00Z');
        $this->assertSame(31 * 86400, $until->getTimestamp() - $from->getTimestamp());
    }
}
