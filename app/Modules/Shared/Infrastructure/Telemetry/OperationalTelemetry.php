<?php

namespace App\Modules\Shared\Infrastructure\Telemetry;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Throwable;

class OperationalTelemetry
{
    public function __construct(private ?TransportFactoryInterface $factory = null) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, int>  $gauges
     */
    public function record(string $operation, array $attributes, array $gauges = [], ?int $startedAt = null): bool
    {
        if (! config('modernization.telemetry.enabled')) {
            return true;
        }
        $scope = null;
        try {
            $endpoint = rtrim((string) config('modernization.telemetry.endpoint'), '/');
            $factory = $this->factory ?? new PsrTransportFactory(
                new Client(['connect_timeout' => 0.2, 'timeout' => 0.35, 'http_errors' => false]), new HttpFactory, new HttpFactory,
            );
            $transport = fn (string $signal) => $factory->create($endpoint.'/v1/'.$signal, 'application/json', timeout: 0.35, maxRetries: 0);
            $resource = ResourceInfo::create(Attributes::create([
                'service.name' => 'servicios.reposs', 'deployment.environment.name' => app()->environment(),
            ]));
            $traces = TracerProvider::builder()->setResource($resource)
                ->addSpanProcessor(new SimpleSpanProcessor(new SpanExporter($transport('traces'))))->build();
            $logs = LoggerProvider::builder()->setResource($resource)
                ->addLogRecordProcessor(new SimpleLogRecordProcessor(new LogsExporter($transport('logs'))))->build();
            $metrics = MeterProvider::builder()->setResource($resource)
                ->addReader(new ExportingReader(new MetricExporter($transport('metrics'))))->build();
            $operation = in_array($operation, ['http.request', 'outbox.batch'], true) ? $operation : 'operation';
            $safe = [];
            foreach (['correlationId', 'http.route', 'http.request.method', 'http.response.status_code'] as $key) {
                if (isset($attributes[$key]) && (is_string($attributes[$key]) || is_int($attributes[$key]))) {
                    $safe[$key] = $attributes[$key];
                }
            }
            $span = $traces->getTracer('servicios.operational')->spanBuilder($operation)->setAttributes($safe);
            if ($startedAt !== null) {
                $span->setStartTimestamp($startedAt);
            }
            $active = $span->startSpan();
            $scope = $active->activate();
            $logs->getLogger('servicios.operational')->logRecordBuilder()->setBody($operation)
                ->setSeverityText('INFO')->setAttributes($safe)->emit();
            $meter = $metrics->getMeter('servicios.operational');
            $labels = ['operation' => $operation];
            $meter->createCounter('servicios.operations')->add(1, $labels);
            if ($startedAt !== null) {
                $meter->createHistogram('servicios.operation.duration', 's')->record(max(0, (microtime(true) * 1e9 - $startedAt) / 1e9), $labels);
            }
            foreach (['pending', 'processing', 'failed', 'expired_leases', 'oldest_eligible_seconds', 'heartbeat_age_seconds'] as $name) {
                if (isset($gauges[$name])) {
                    $meter->createGauge('servicios.outbox.'.$name)->record($gauges[$name]);
                }
            }
            $active->end();
            $a = $traces->shutdown();
            $b = $logs->shutdown();
            $c = $metrics->shutdown();

            return $a && $b && $c;
        } catch (Throwable) {
            return false;
        } finally {
            $scope?->detach();
        }
    }
}
