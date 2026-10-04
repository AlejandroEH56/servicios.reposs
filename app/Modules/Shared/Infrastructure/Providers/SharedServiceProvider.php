<?php

namespace App\Modules\Shared\Infrastructure\Providers;

use App\Modules\Shared\Application\Ports\ReadinessCheck;
use App\Modules\Shared\Infrastructure\Health\ReadinessProbe;
use App\Modules\Shared\Infrastructure\Outbox\OutboxProcessor;
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
        $this->app->bind(ReadinessCheck::class, ReadinessProbe::class);
        $this->app->bind(OutboxProcessor::class, function () {
            return new OutboxProcessor([
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
