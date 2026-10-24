<?php

namespace Tests\Unit;

use App\Modules\Residencies\Domain\Services\ResidencyRules;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ResidencyRulesTest extends TestCase
{
    public static function validRfcs(): array
    {
        return [['XAXX010101000', 'XAXX010101000'], [' xexx010101000 ', 'XEXX010101000'], ['ABC010101AB1', 'ABC010101AB1'], ['ñaa010101aa6', 'ÑAA010101AA6']];
    }

    #[DataProvider('validRfcs')]
    public function test_rfc_normalization_and_check_digits(string $input, string $expected): void
    {
        $this->assertSame($expected, ResidencyRules::rfc($input));
    }

    public static function invalidRfcs(): array
    {
        return [[''], ['ABC010101AB2'], ['ABC130101AB_'], ['ABC011301AB1'], ['ABC010000AB1'], ['ABC-010101-AB1'], ['<script>']];
    }

    #[DataProvider('invalidRfcs')]
    public function test_invalid_rfc_is_rejected(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        ResidencyRules::rfc($input);
    }

    public function test_inclusive_overlap_rejects_shared_end_day_but_accepts_next_day(): void
    {
        $this->assertTrue(ResidencyRules::overlaps('2026-01-01', '2026-04-30', '2026-04-30', '2026-08-30'));
        $this->assertFalse(ResidencyRules::overlaps('2026-01-01', '2026-04-30', '2026-05-01', '2026-08-30'));
        ResidencyRules::contained('2026-02-01', '2026-02-01', '2026-02-01', '2026-02-01');
    }

    public static function invalidRanges(): array
    {
        return [['2026-02-30', '2026-03-01'], ['2026-10-02', '2026-10-01'], ['2026-1-01', '2026-02-01'], ['2026-01-01', 'not-a-date']];
    }

    #[DataProvider('invalidRanges')]
    public function test_invalid_calendar_dates_and_order_are_rejected(string $start, string $end): void
    {
        $this->expectException(InvalidArgumentException::class);
        ResidencyRules::dates($start, $end);
    }

    public function test_project_cannot_leave_parent_period(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ResidencyRules::contained('2026-01-01', '2026-06-30', '2026-02-01', '2026-06-30');
    }

    public function test_report_counts_are_derived_by_server_from_approved_duration(): void
    {
        $this->assertSame(2, ResidencyRules::partialReports(4));
        $this->assertSame(3, ResidencyRules::partialReports(5));
        $this->assertSame(3, ResidencyRules::partialReports(6));
        $this->expectException(InvalidArgumentException::class);
        ResidencyRules::partialReports(7);
    }
}
