<?php

namespace Tests\Unit;

use App\Modules\Publications\Domain\Services\NoticeContent;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NoticeContentTest extends TestCase
{
    public static function invalidInputs(): array
    {
        return [['text', '<img src=x onerror=alert(1)>'], ['text', "control\x00byte"], ['url', 'javascript:alert(1)'],
            ['url', 'https://user:pass@example.test'], ['instant', '2026-02-30T12:00:00Z'], ['instant', '2026-10-19'], ['instant', '2026-10-19T99:00:00Z']];
    }

    #[DataProvider('invalidInputs')]
    public function test_rejects_unsafe_text_urls_and_ambiguous_dates(string $method, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        if ($method === 'text') {
            NoticeContent::text($value, 200);
        } else {
            NoticeContent::$method($value);
        }
    }

    public function test_instants_are_normalized_to_utc_and_valid_plain_text_is_preserved(): void
    {
        $this->assertSame('2026-10-19 18:00:00.000000', NoticeContent::instant('2026-10-19T12:00:00-06:00'));
        $this->assertSame('Convocatoria pública', NoticeContent::text(' Convocatoria pública ', 200, true));
    }
}
