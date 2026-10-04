<?php

namespace App\Modules\Shared\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Application\Ports\ReadinessCheck;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'live'])->header('Cache-Control', 'no-store');
    }

    public function ready(ReadinessCheck $probe): JsonResponse
    {
        $ready = $probe->isReady();

        return response()->json(['status' => $ready ? 'ready' : 'unavailable'], $ready ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
