<?php

use App\Http\Middleware\CorrelationId;
use App\Http\Middleware\OperationalTelemetry;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            require __DIR__.'/../app/Modules/Shared/Presentation/Routes/health.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(CorrelationId::class);
        $middleware->append(OperationalTelemetry::class);
        $middleware->trustProxies(at: ['127.0.0.1', '::1', '172.30.0.2'], headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'health/*') || $request->expectsJson(),
        );
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*', 'health/*')) {
                return null;
            }

            $status = match (true) {
                $exception instanceof ValidationException => 422,
                $exception instanceof AuthenticationException => 401,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            };
            [$code, $title] = match ($status) {
                400 => ['MALFORMED_REQUEST', 'Solicitud inválida'],
                401 => ['AUTHENTICATION_REQUIRED', 'Autenticación requerida'],
                403 => ['FORBIDDEN', 'Acceso denegado'],
                404 => ['RESOURCE_NOT_FOUND', 'Recurso no encontrado'],
                405 => ['METHOD_NOT_ALLOWED', 'Método no permitido'],
                409 => ['STATE_CONFLICT', 'Conflicto de estado'],
                413 => ['FILE_TOO_LARGE', 'Archivo demasiado grande'],
                415 => ['UNSUPPORTED_MEDIA_TYPE', 'Tipo de contenido no soportado'],
                422 => ['VALIDATION_ERROR', 'La solicitud no es válida'],
                429 => ['RATE_LIMITED', 'Demasiadas solicitudes'],
                503 => ['DEPENDENCY_UNAVAILABLE', 'Servicio no disponible'],
                default => ['INTERNAL_ERROR', 'Error interno'],
            };
            $correlationId = $request->attributes->get('correlationId', (string) Str::uuid());
            $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

            return response()->json([
                'type' => 'about:blank',
                'title' => $title,
                'status' => $status,
                'detail' => $title,
                'instance' => '/'.$request->path(),
                'code' => $code,
                'correlationId' => $correlationId,
            ], $status, $headers)->withHeaders([
                'Content-Type' => 'application/problem+json',
                'X-Correlation-ID' => $correlationId,
                'Cache-Control' => 'no-store',
            ]);
        });
    })->create();
