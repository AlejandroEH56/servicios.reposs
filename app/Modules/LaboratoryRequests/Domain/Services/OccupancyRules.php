<?php

namespace App\Modules\LaboratoryRequests\Domain\Services;

final class OccupancyRules
{
    /** @param list<array{startAt: string, endAt: string}> $intervals
     * @param list<array{startAt: string, endAt: string}> $blocks
     * @return list<array{startAt: string, endAt: string}> */
    public static function subtract(array $intervals, array $blocks): array
    {
        foreach ($blocks as $block) {
            $remaining = [];
            foreach ($intervals as $slot) {
                if ($block['endAt'] <= $slot['startAt'] || $block['startAt'] >= $slot['endAt']) {
                    $remaining[] = $slot;

                    continue;
                }
                if ($block['startAt'] > $slot['startAt']) {
                    $remaining[] = ['startAt' => $slot['startAt'], 'endAt' => $block['startAt']];
                }
                if ($block['endAt'] < $slot['endAt']) {
                    $remaining[] = ['startAt' => $block['endAt'], 'endAt' => $slot['endAt']];
                }
            }
            $intervals = $remaining;
        }

        return $intervals;
    }
}
