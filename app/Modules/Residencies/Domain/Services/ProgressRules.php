<?php

namespace App\Modules\Residencies\Domain\Services;

use DateTimeImmutable;
use InvalidArgumentException;

class ProgressRules
{
    /**
     * @return list<array{type:string,sequence:int,startsOn:string,endsOn:string}> */
    public static function plan(int $months, string $startsOn, string $endsOn): array
    {
        $partials = ResidencyRules::partialReports($months);
        ResidencyRules::dates($startsOn, $endsOn);
        $start = new DateTimeImmutable($startsOn);
        $days = (int) $start->diff(new DateTimeImmutable($endsOn))->days + 1;
        if ($days < $partials) {
            throw new InvalidArgumentException('REPORT_WINDOW_TOO_SHORT');
        }
        $plan = [];
        for ($i = 0; $i < $partials; $i++) {
            $from = (int) floor($days * $i / $partials);
            $until = (int) floor($days * ($i + 1) / $partials) - 1;
            $plan[] = ['type' => 'PARCIAL', 'sequence' => $i + 1,
                'startsOn' => $start->modify('+'.$from.' days')->format('Y-m-d'),
                'endsOn' => $start->modify('+'.$until.' days')->format('Y-m-d')];
        }
        $plan[] = ['type' => 'FINAL', 'sequence' => 1, 'startsOn' => $startsOn, 'endsOn' => $endsOn];

        return $plan;
    }

    public static function grade(string $decision, mixed $grade): void
    {
        if ($decision === 'APROBADO' && (! is_numeric($grade) || ! is_finite((float) $grade) || (float) $grade < 0 || (float) $grade > 100)) {
            throw new InvalidArgumentException('REPORT_GRADE_REQUIRED');
        }
        if ($decision !== 'APROBADO' && $grade !== null) {
            throw new InvalidArgumentException('REJECTED_REPORT_HAS_NO_GRADE');
        }
    }

    public static function reason(?string $reason): string
    {
        $reason = trim($reason ?? '');
        if ($reason === '' || mb_strlen($reason) > 5000) {
            throw new InvalidArgumentException('REASON_REQUIRED');
        }

        return $reason;
    }

    /**
     * @param list<array<string,mixed>> $reports
     * @return list<array{sequence:int,evidenceId:?string,reviewNumber:int}> */
    public static function dependencies(array $reports, string $type, int $sequence): array
    {
        $rows = array_values(array_filter($reports, fn (array $row): bool => $row['type'] === 'PARCIAL' && ($type === 'FINAL' || $row['sequence'] < $sequence)));
        usort($rows, fn (array $a, array $b): int => $a['sequence'] <=> $b['sequence']);

        return array_map(fn (array $row): array => ['sequence' => (int) $row['sequence'], 'evidenceId' => $row['evidenceId'], 'reviewNumber' => (int) $row['reviewNumber']], $rows);
    }

    /**
     * @param list<array<string,mixed>> $reports
     * @param array<string,mixed> $report */
    public static function dependenciesCurrent(array $reports, array $report): bool
    {
        return $report['evidenceId'] === null || $report['dependencies'] === DocumentRules::canonical(self::dependencies($reports, $report['type'], (int) $report['sequence']));
    }
}
