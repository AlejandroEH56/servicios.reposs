<?php

namespace Tests\Unit;

use App\Modules\Residencies\Domain\Services\EvidenceRules;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class EvidenceRulesTest extends TestCase
{
    public function test_original_names_cannot_carry_paths_controls_or_unbounded_text(): void
    {
        $this->assertSame('proof.txt', EvidenceRules::originalName("C:\\private\\proof\r\n.txt"));
        $this->assertSame('evidence.pdf', EvidenceRules::originalName('../../evidence.pdf'));
        $this->assertSame('document', EvidenceRules::originalName("\r\n"));
        $this->assertSame(180, mb_strlen(EvidenceRules::originalName(str_repeat('ñ', 300))));
    }

    public function test_replacement_and_inclusive_validity_rules(): void
    {
        foreach (['ENVIADO', 'RECHAZADO'] as $state) {
            $this->assertTrue(EvidenceRules::replaceable($state));
        }
        foreach (['APROBADO', 'REEMPLAZADO', 'RETIRADO'] as $state) {
            $this->assertFalse(EvidenceRules::replaceable($state));
        }
        $this->assertTrue(EvidenceRules::inForce('2026-10-24', '2026-10-24', '2026-10-24'));
        $this->assertTrue(EvidenceRules::inForce(null, null, '2026-10-24'));
        $this->assertFalse(EvidenceRules::inForce('2026-10-25', null, '2026-10-24'));
        $this->assertFalse(EvidenceRules::inForce(null, '2026-10-23', '2026-10-24'));
    }

    public function test_change_requests_preserve_the_decision_and_materialize_rejection(): void
    {
        $this->assertSame('APROBADO', EvidenceRules::decisionState('APROBADO', null));
        $this->assertSame('RECHAZADO', EvidenceRules::decisionState('RECHAZADO', 'Motivo de ensayo'));
        $this->assertSame('RECHAZADO', EvidenceRules::decisionState('CAMBIOS_SOLICITADOS', 'Corrección requerida'));
    }

    public function test_rejection_requires_nonempty_observations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EvidenceRules::decisionState('RECHAZADO', '  ');
    }
}
