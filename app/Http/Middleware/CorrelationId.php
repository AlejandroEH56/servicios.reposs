<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CorrelationId
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $candidate = $request->header('X-Correlation-ID', '');
        $id = Str::isUuid($candidate)
            ? strtolower($candidate)
            : (string) Str::uuid();

        $request->attributes->set('correlationId', $id);
        $response = $next($request);
        $response->headers->set('X-Correlation-ID', $id);

        return $response;
    }
}
