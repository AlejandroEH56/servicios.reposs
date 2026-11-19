<?php

namespace App\Modules\LaboratoryRequests\Infrastructure\Documents;

use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

class ReceiptRenderer
{
    /** @param array<string, mixed> $snapshot */
    public function pdf(array $snapshot): string
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
        $renderer = new Dompdf($options);
        $renderer->loadHtml(view('laboratory-requests.receipt', ['snapshot' => $snapshot])->render(), 'UTF-8');
        $renderer->setPaper('letter', 'portrait');
        $renderer->render();
        $bytes = $renderer->output();
        if (! str_starts_with($bytes, '%PDF-') || strlen($bytes) > 10485760) {
            throw new RuntimeException('RENDER_OUTPUT_REJECTED');
        }

        return $bytes;
    }
}
