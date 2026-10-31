<?php

namespace Tests\Unit;

use App\Modules\Residencies\Domain\Services\DocumentRules;
use App\Modules\Residencies\Domain\Services\ProgressRules;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProgressRulesTest extends TestCase
{
    /** @return array<string,array{int,int}> */
    public static function durations(): array
    {
        return ['four months' => [4, 2], 'five months' => [5, 3], 'six months' => [6, 3]];
    }

    #[DataProvider('durations')]
    public function test_report_windows_cover_project_without_gaps_or_overlaps(int $months, int $partials): void
    {
        $plan = ProgressRules::plan($months, '2024-02-01', '2024-07-31');
        $this->assertCount($partials + 1, $plan);
        $cursor = '2024-02-01';
        foreach (array_slice($plan, 0, $partials) as $index => $report) {
            $this->assertSame('PARCIAL', $report['type']);
            $this->assertSame($index + 1, $report['sequence']);
            $this->assertSame($cursor, $report['startsOn']);
            $this->assertGreaterThanOrEqual($report['startsOn'], $report['endsOn']);
            $cursor = (new \DateTimeImmutable($report['endsOn']))->modify('+1 day')->format('Y-m-d');
        }
        $this->assertSame('2024-08-01', $cursor);
        $this->assertSame(['type' => 'FINAL', 'sequence' => 1, 'startsOn' => '2024-02-01', 'endsOn' => '2024-07-31'], $plan[$partials]);
    }

    public function test_report_plan_rejects_a_window_too_short_for_the_required_partials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProgressRules::plan(5, '2026-01-01', '2026-01-02');
    }

    /** @return array<string,array{string,mixed}> */
    public static function invalidGrades(): array
    {
        return ['missing' => ['APROBADO', null], 'negative' => ['APROBADO', -1], 'over one hundred' => ['APROBADO', 100.01],
            'not finite' => ['APROBADO', NAN], 'infinite' => ['APROBADO', INF], 'rejected with grade' => ['RECHAZADO', 60], 'changes with grade' => ['CAMBIOS_SOLICITADOS', 10]];
    }

    #[DataProvider('invalidGrades')]
    public function test_report_grades_are_explicit_bounded_and_consistent(string $decision, mixed $grade): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProgressRules::grade($decision, $grade);
    }

    public function test_reopening_a_predecessor_invalidates_the_recorded_dependency_revision(): void
    {
        $reports = [['type' => 'PARCIAL', 'sequence' => 1, 'evidenceId' => 'a', 'reviewNumber' => 1, 'dependencies' => '[]'],
            ['type' => 'PARCIAL', 'sequence' => 2, 'evidenceId' => 'b', 'reviewNumber' => 1, 'dependencies' => '[]']];
        $snapshot = DocumentRules::canonical(ProgressRules::dependencies($reports, 'FINAL', 1));
        $final = ['type' => 'FINAL', 'sequence' => 1, 'evidenceId' => 'c', 'reviewNumber' => 1, 'dependencies' => $snapshot];
        $this->assertTrue(ProgressRules::dependenciesCurrent($reports, $final));
        $reports[0]['reviewNumber'] = 2;
        $this->assertFalse(ProgressRules::dependenciesCurrent($reports, $final));
        $this->assertSame([['sequence' => 1, 'evidenceId' => 'a', 'reviewNumber' => 2]], ProgressRules::dependencies($reports, 'PARCIAL', 2));
    }

    public function test_snapshot_canonicalization_and_folios_preserve_identity_and_sequence(): void
    {
        $this->assertSame(DocumentRules::canonical(['z' => ['b' => 2, 'a' => 1], 'a' => ['second', 'first']]), DocumentRules::canonical(['a' => ['second', 'first'], 'z' => ['a' => 1, 'b' => 2]]));
        $this->assertNotSame(DocumentRules::canonical(['items' => ['a', 'b']]), DocumentRules::canonical(['items' => ['b', 'a']]));
        $this->assertSame('ITSH/RPSS/2026(1)/001', DocumentRules::folio(2026, 1, 1));
        $this->assertSame('ITSH/RPSS/2026(2)/1000', DocumentRules::folio(2026, 2, 1000));
        $this->assertSame(['2026-02-01', '2026-07-31'], DocumentRules::semester(2026, 1));
        $this->assertSame(['2026-08-01', '2026-12-31'], DocumentRules::semester(2026, 2));
    }
}
