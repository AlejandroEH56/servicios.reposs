<?php

namespace Tests\Unit;

use App\Modules\Organization\Domain\Services\JobTitleParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JobTitleParserTest extends TestCase
{
    /** @return list<array{0: ?string, 1: list<string>}> */
    public static function chains(): array
    {
        return [
            [null, []], ['', []], ['   ', []],
            ['Docente / Jefe / Docente', ['Docente', 'Jefe']],
            ['  Docente /  Jefe / docente  ', ['Docente', 'Jefe']],
            ['Docente/Jefe', ['Docente/Jefe']],
            [' / Docente / ', ['Docente']],
            ['Laboratorista / Docente', ['Laboratorista', 'Docente']],
        ];
    }

    #[DataProvider('chains')]
    public function test_ordered_distinct_positions_and_empty_profiles(?string $chain, array $expected): void
    {
        $this->assertSame($expected, (new JobTitleParser)->parse($chain));
    }

    public function test_invalid_control_characters_and_overlong_positions_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new JobTitleParser)->parse(str_repeat('a', 181));
    }
}
