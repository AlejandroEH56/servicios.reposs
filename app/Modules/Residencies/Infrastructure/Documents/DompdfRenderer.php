<?php

namespace App\Modules\Residencies\Infrastructure\Documents;

use App\Modules\Residencies\Application\Ports\DocumentRenderer;
use App\Modules\Residencies\Domain\Services\DocumentRules;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

class DompdfRenderer implements DocumentRenderer
{
    public function html(array $snapshot): string
    {
        $view = DocumentRules::VIEWS[$snapshot['type']] ?? null;
        if ($view === null) {
            throw new RuntimeException('UNSUPPORTED_DOCUMENT_TYPE');
        }
        $data = $snapshot['fields'];
        $images = array_map(fn (array $asset): array => ['data_uri' => $asset['dataUri']], $snapshot['assets']);

        return view('residencies.documents.'.$view, ['data' => $data, 'imagenes' => $images] + ($snapshot['certificateFields'] ?? []))->render();
    }

    public function pdf(string $html): string
    {
        $cache = storage_path('framework/cache/dompdf');
        if (! is_dir($cache) && ! mkdir($cache, 0770, true) && ! is_dir($cache)) {
            throw new RuntimeException('RENDER_CACHE_UNAVAILABLE');
        }
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setAllowedProtocols(['data://']);
        $options->setChroot([resource_path('documents')]);
        $options->setFontCache($cache);
        $options->setTempDir($cache);
        $options->setDefaultFont('DejaVu Sans');
        $options->setDpi(96);
        $options->setImageByteSizeLimit(16 * 1024 * 1024);
        $renderer = new Dompdf($options);
        $renderer->loadHtml($html, 'UTF-8');
        $renderer->setPaper('letter', 'portrait');
        $renderer->render();
        $bytes = $renderer->output();
        if (! str_starts_with($bytes, '%PDF-') || strlen($bytes) > 10485760) {
            throw new RuntimeException('RENDER_OUTPUT_REJECTED');
        }

        return $bytes;
    }
}
