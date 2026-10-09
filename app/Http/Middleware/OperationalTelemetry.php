<?php

namespace App\Http\Middleware;

use App\Modules\Shared\Infrastructure\Telemetry\OperationalTelemetry as Telemetry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OperationalTelemetry
{
    public function __construct(private Telemetry $telemetry) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $started = (int) (microtime(true) * 1e9);
        $status = 500;
        try {
            $response = $next($request);
            $status = $response->getStatusCode();

            return $response;
        } finally {
            $route = $request->route()?->uri() ?? 'unmatched';
            $route = preg_match('#^(api/v1/me|health/(live|ready)|auth/(microsoft|entra)/(callback|login)|auth/microsoft|auth/logout|login|dashboard|/)$#', $route) ? $route : 'other';
            $this->telemetry->record('http.request', [
                'correlationId' => $request->attributes->get('correlationId'), 'http.route' => $route,
                'http.request.method' => $request->method(), 'http.response.status_code' => $status,
            ], startedAt: $started);
        }
    }
}
