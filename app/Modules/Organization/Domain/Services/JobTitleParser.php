<?php

namespace App\Modules\Organization\Domain\Services;

use InvalidArgumentException;

final class JobTitleParser
{
    /** @return list<string> */
    public function parse(?string $jobTitle): array
    {
        if ($jobTitle === null || trim($jobTitle) === '') {
            return [];
        }
        if (mb_strlen($jobTitle) > 1000 || preg_match('/[\x00-\x1F\x7F]/u', $jobTitle)) {
            throw new InvalidArgumentException('INVALID_JOB_TITLE');
        }
        $result = [];
        $seen = [];
        foreach (preg_split('/\s+\/\s+/u', $jobTitle) ?: [] as $part) {
            $name = trim($part);
            if ($name === '') {
                continue;
            }
            if (mb_strlen($name) > 180) {
                throw new InvalidArgumentException('INVALID_POSITION_NAME');
            }
            $key = mb_strtolower($name);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $name;
            }
        }

        return $result;
    }
}
