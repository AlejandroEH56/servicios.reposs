<?php

namespace Tests\Feature;

use App\Modules\Residencies\Domain\Services\DocumentRules;
use App\Modules\Residencies\Infrastructure\Documents\DompdfRenderer;
use App\Modules\Shared\Application\Ports\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Support\CompletionFixture;
use Tests\TestCase;

class ResidencyDocumentRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_six_legacy_formats_keep_text_layout_images_and_frozen_server_fields(): void
    {
        Storage::fake('local');
        $this->app->instance(VirusScanner::class, new class implements VirusScanner
        {
            public function isClean(string $path): bool
            {
                return true;
            }
        });
        $fixture = new CompletionFixture;
        $fixture->initializeAndApprove();
        $fixture->completeReports();
        $fixture->release();
        $renderer = app(DompdfRenderer::class);
        $directory = base_path('artifacts/sprint8-pdf-regression');
        if (! is_dir($directory)) {
            mkdir($directory, 0770, true);
        }
        if (! defined('FCPATH')) {
            define('FCPATH', resource_path('documents/logos/'));
        }
        require_once base_path('tests/Fixtures/Residencies/legacy/helpers.php.stub');
        $results = [];
        foreach (DocumentRules::TYPES as $type) {
            $extra = match ($type) {
                'SOLICITUD_RESIDENCIA' => ['recipientContactId' => $fixture->contact],
                'FORMATO_REPORTE_PARCIAL','FORMATO_REPORTE_FINAL' => ['reportSequence' => 1],
                'CONSTANCIA_ASESORIA' => ['adviserAssignmentId' => $fixture->assignment, 'year' => 2026, 'semester' => 1, 'startsOn' => '2026-02-01', 'endsOn' => '2026-07-31'],default => []
            };
            $issued = $fixture->issue($fixture->template($type), $extra);
            $record = DB::table('residencias_documentos_emitidos')->where('id', $issued['id'])->first();
            $snapshot = json_decode($record->datos_emision, true, flags: JSON_THROW_ON_ERROR);
            $name = DocumentRules::VIEWS[$type];
            foreach (array_merge(glob($directory.'/'.$name.'-[0-9]*.png'), glob($directory.'/'.$name.'-reference-[0-9]*.png')) as $previousPage) {
                unlink($previousPage);
            }
            $modernHtml = $record->html_emision;
            $legacyHtml = $this->legacy($name, $snapshot);
            $this->assertStringNotContainsString('file_get_contents', $modernHtml);
            $this->assertStringNotContainsString('src="http', $modernHtml);
            $this->assertSame(count($snapshot['assets']), substr_count($modernHtml, 'src="data:image/'));
            $modern = $renderer->pdf($modernHtml);
            $legacy = $renderer->pdf($legacyHtml);
            $this->assertStringStartsWith('%PDF-', $modern);
            $this->assertGreaterThan(5000, strlen($modern));
            file_put_contents($directory.'/'.$name.'.pdf', $modern);
            file_put_contents($directory.'/'.$name.'-reference.pdf', $legacy);
            $result = ['type' => $type, 'sourceHash' => hash_file('sha256', base_path('tests/Fixtures/Residencies/legacy/'.$name.'.php.stub')), 'pdfSha256' => hash('sha256', $modern), 'bytes' => strlen($modern)];
            if ($type === 'FORMATO_REPORTE_FINAL') {
                $result['intentionalDifference'] = 'Footer reduced to 9 px to retain the complete document on one page; every reference word preserved.';
            }
            if (getenv('S8_PDF_POPPLER') === '1') {
                $modernText = $this->poppler('pdftotext', [$name.'.pdf', '-'], $directory);
                $legacyText = $this->poppler('pdftotext', [$name.'-reference.pdf', '-'], $directory);
                if ($type === 'FORMATO_REPORTE_FINAL') {
                    $referenceWords = preg_split('/\s+/u', trim($legacyText));
                    $modernWords = preg_split('/\s+/u', trim($modernText));
                    sort($referenceWords);
                    sort($modernWords);
                    $this->assertSame($referenceWords, $modernWords, 'Footer adjustment must preserve every word');
                } else {
                    $this->assertSame($legacyText, $modernText, $type.' text or pagination changed');
                }
                $this->assertStringContainsString($type === 'CONSTANCIA_ASESORIA' ? 'ITSH/RPSS/2026(1)/001' : '99009901', $modernText);
                $info = $this->poppler('pdfinfo', [$name.'.pdf'], $directory);
                preg_match('/Pages:\s+(\d+)/', $info, $pages);
                $this->assertNotEmpty($pages);
                $result['pages'] = (int) $pages[1];
                $this->assertSame($type === 'CONSTANCIA_ASESORIA' ? 2 : 1, $result['pages'], 'No trailing blank page');
                $this->poppler('pdftoppm', ['-scale-to', '1200', '-png', $name.'.pdf', $name], $directory);
                $this->poppler('pdftoppm', ['-scale-to', '1200', '-png', $name.'-reference.pdf', $name.'-reference'], $directory);
                foreach (glob($directory.'/'.$name.'-[0-9]*.png') as $page) {
                    $reference = str_replace($name.'-', $name.'-reference-', basename($page));
                    $this->assertFileExists($directory.'/'.$reference);
                    if ($type !== 'FORMATO_REPORTE_FINAL') {
                        $this->assertSame(hash_file('sha256', $directory.'/'.$reference), hash_file('sha256', $page), $type.' visual rendering differs');
                    }
                }
            }
            $results[] = $result;
        }
        file_put_contents($directory.'/comparison.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $snapshot */
    private function legacy(string $name, array $snapshot): string
    {
        $data = $snapshot['fields'];
        $imagenes = array_map(fn (array $asset): array => ['ruta_archivo' => $asset['builtin']], $snapshot['assets']);
        extract($snapshot['certificateFields'] ?? [], EXTR_SKIP);
        ob_start();
        try {
            include base_path('tests/Fixtures/Residencies/legacy/'.$name.'.php.stub');

            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /** @param list<string> $arguments */
    private function poppler(string $tool, array $arguments, string $directory): string
    {
        $docker = getenv('S8_PDF_QA_DOCKER');
        $command = $docker ? [$docker, 'run', '--rm', '--network', 'none', '--mount', 'type=bind,source='.$directory.',target=/work', 'servicios-pdf-qa:local', $tool, ...$arguments] : [$tool, ...$arguments];
        $process = new Process($command, $directory, timeout: 120);
        $process->mustRun();

        return $process->getOutput();
    }
}
