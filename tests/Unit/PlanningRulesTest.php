<?php

namespace Tests\Unit;

use App\Modules\AcademicPlanning\Domain\Services\PlanningRules;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PlanningRulesTest extends TestCase
{
    /**
     * A basic unit test example.
     */
    public function test_plain_text_and_utc_intervals_are_canonical(): void
    {
        $this->assertSame('Laboratorio de redes', PlanningRules::text(' Laboratorio de redes '));
        $this->assertSame('2026-10-22 18:00:00.000000', PlanningRules::instant('2026-10-22T12:00:00-06:00'));
        $this->assertSame('ACTIVO', PlanningRules::transition('PLANIFICADO', 'activate'));
        $this->assertSame('CERRADO', PlanningRules::transition('ACTIVO', 'close'));
        PlanningRules::dates('2026-08-01', '2026-12-18');
    }

    public function test_impossible_calendar_date_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PlanningRules::dates('2026-02-30', '2026-12-18');
    }

    public function test_html_is_not_catalog_text(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PlanningRules::text('<img src=x onerror=alert(1)>');
    }

    public function test_invalid_offset_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PlanningRules::instant('2026-10-22T12:00:00+14:30');
    }

    public function test_closed_semester_cannot_be_reactivated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PlanningRules::transition('CERRADO', 'activate');
    }
}
