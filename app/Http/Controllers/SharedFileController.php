<?php

namespace App\Http\Controllers;

use App\Modules\Shared\Infrastructure\Storage\PrivateFileStore;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

class SharedFileController extends Controller
{
    public function __invoke(Request $request, string $file, PrivateFileStore $store): Response
    {
        try {
            $result = $store->read($file, (string) $request->user()->getAuthIdentifier());
        } catch (RuntimeException) {
            abort(403);
        }

        return response($result['content'])->withHeaders([
            'Content-Type' => $result['mime'], 'Content-Disposition' => 'attachment; filename="'.$file.'"',
            'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
