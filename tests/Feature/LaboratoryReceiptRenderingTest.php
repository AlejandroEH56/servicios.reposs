<?php

namespace Tests\Feature;

use App\Modules\LaboratoryRequests\Infrastructure\Documents\ReceiptRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LaboratoryReceiptRenderingTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function types(): array
    {
        return ['practica' => ['PRACTICA'], 'varia' => ['VARIA'], 'extraordinaria' => ['EXTRAORDINARIA']];
    }

    #[DataProvider('types')]
    public function test_receipts_preserve_request_fields_and_escape_untrusted_content(string $type): void
    {
        $snapshot = ['request' => [
            'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'type' => $type, 'status' => 'AUTORIZADO',
            'title' => 'Ensayo Sprint 10', 'startAt' => '2027-01-04T15:00:00Z', 'endAt' => '2027-01-04T16:00:00Z',
            'attendees' => 12, 'purpose' => 'Actividad académica de caracterización',
            'supplies' => 'Material de ensayo sin datos personales.',
            'submittedAt' => '2027-01-01T12:00:00Z',
            'practice' => ['name' => 'Práctica de ensayo', 'number' => '1'],
            'various' => ['useCode' => 'PROYECTO', 'description' => 'Actividad de ensayo'],
            'extraordinary' => ['justification' => 'Actividad extraordinaria de ensayo'],
        ], 'laboratory' => 'Laboratorio de ensayo', 'capacity' => 25,
            'academic' => ['career' => 'Carrera de ensayo', 'subject' => 'Asignatura de ensayo', 'subjectCode' => 'S10-PILOTO', 'group' => 'Grupo de ensayo'],
            'requester' => 'Solicitante de ensayo', 'requesterEmail' => 'ensayo@example.test', 'reviewer' => 'Revisor de ensayo',
            'decisionId' => '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'reason' => 'Horario y capacidad comprobados',
            'decidedAt' => '2027-01-02T12:00:00Z'];
        $html = view('laboratory-requests.receipt', compact('snapshot'))->render();
        foreach (['Laboratorio de ensayo', 'ensayo@example.test', '25', '12', 'Actividad académica', '04/01/2027 09:00', 'AUTORIZADO'] as $field) {
            $this->assertStringContainsString($field, $html);
        }
        $pdf = app(ReceiptRenderer::class)->pdf($snapshot);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(5000, strlen($pdf));
        if (getenv('S10_PDF_QA') === '1') {
            $directory = base_path('artifacts/sprint10-pdf-qa');
            if (! is_dir($directory)) {
                mkdir($directory, 0770, true);
            }
            file_put_contents($directory.'/'.strtolower($type).'.pdf', $pdf);
        }
        $snapshot['request']['title'] = '<script>alert(1)</script><img src="http://127.0.0.1/private">';
        $escaped = view('laboratory-requests.receipt', compact('snapshot'))->render();
        $this->assertStringNotContainsString('<script>', $escaped);
        $this->assertStringNotContainsString('<img ', $escaped);
        $this->assertStringContainsString('&lt;script&gt;', $escaped);
    }
}
