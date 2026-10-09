<?php

namespace Tests\Feature;

use App\Modules\Shared\Infrastructure\Telemetry\OperationalTelemetry;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use Tests\TestCase;

class TelemetryTest extends TestCase
{
    public function test_trace_log_and_metrics_are_exported_with_correlated_context_and_without_sensitive_fields(): void
    {
        config(['modernization.telemetry.enabled' => true]);
        $history = [];
        $handler = HandlerStack::create(new MockHandler(array_fill(0, 3, new Response(200, ['Content-Type' => 'application/json'], '{}'))));
        $handler->push(Middleware::history($history));
        $factory = new PsrTransportFactory(new Client(['handler' => $handler]), new HttpFactory, new HttpFactory);
        $telemetry = new OperationalTelemetry($factory);
        $id = (string) Str::uuid();
        $this->assertTrue($telemetry->record('http.request', [
            'correlationId' => $id, 'http.route' => 'api/v1/me', 'http.request.method' => 'GET', 'http.response.status_code' => 401,
            'user.email' => 'sensitive@example.test', 'http.request.header.cookie' => 'private-cookie',
            'db.statement' => 'SELECT private', 'token' => 'private-token',
        ], ['pending' => 3], (int) (microtime(true) * 1e9)));
        $this->assertCount(3, $history);
        $signals = [];
        foreach ($history as $entry) {
            $body = (string) $entry['request']->getBody();
            $signals[basename($entry['request']->getUri()->getPath())] = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            foreach (['sensitive@example.test', 'private-cookie', 'SELECT private', 'private-token'] as $sensitive) {
                $this->assertStringNotContainsString($sensitive, $body);
            }
        }
        $span = $signals['traces']['resourceSpans'][0]['scopeSpans'][0]['spans'][0];
        $log = $signals['logs']['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
        $this->assertSame($span['traceId'], $log['traceId']);
        $this->assertSame($span['spanId'], $log['spanId']);
        $this->assertStringContainsString($id, json_encode($log));
        $this->assertStringContainsString('servicios.outbox.pending', json_encode($signals['metrics']));
    }

    public function test_collector_failure_does_not_break_http_or_liveness(): void
    {
        config(['modernization.telemetry.enabled' => true, 'modernization.telemetry.endpoint' => 'http://127.0.0.1:1']);
        $this->get('/health/live')->assertOk();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }
}
