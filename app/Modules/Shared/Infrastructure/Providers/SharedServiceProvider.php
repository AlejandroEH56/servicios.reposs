<?php

namespace App\Modules\Shared\Infrastructure\Providers;

use App\Modules\Organization\Application\ProfileProjector;
use App\Modules\Shared\Application\Ports\ReadinessCheck;
use App\Modules\Shared\Application\Ports\VirusScanner;
use App\Modules\Shared\Infrastructure\Health\ReadinessProbe;
use App\Modules\Shared\Infrastructure\Outbox\OutboxProcessor;
use App\Modules\Shared\Infrastructure\Storage\ClamAvScanner;
use App\Modules\Shared\Infrastructure\Telemetry\OperationalTelemetry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class SharedServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(VirusScanner::class,
            ClamAvScanner::class);
        $this->app->singleton(OperationalTelemetry::class,
            fn () => new OperationalTelemetry);
        $this->app->bind(ReadinessCheck::class, ReadinessProbe::class);
        $this->app->bind(OutboxProcessor::class, function () {
            return new OutboxProcessor([
                'iam.profile-synchronized.v1' => ['organization.profile' => function (array $event): void {
                    $this->app->make(ProfileProjector::class)->handle($event);
                }],
                'iam.identity-linked.v1' => ['audit.identity-linked' => function (array $event): void {
                    DB::table('compartido_registros_auditoria')->insert([
                        'id' => (string) Str::ulid(), 'ocurrido_en' => now(),
                        'id_identidad_actor' => $event['actor'], 'nombre_contexto' => 'IAM',
                        'accion' => 'IDENTITY_LINKED', 'tipo_sujeto' => 'Identity',
                        'id_sujeto' => $event['aggregateId'], 'id_correlacion' => $event['correlationId'],
                    ]);
                }],
            ]);
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
