<?php

namespace App\Modules\Shared\Infrastructure\Providers;

use App\Modules\Shared\Application\Ports\ReadinessCheck;
use App\Modules\Shared\Infrastructure\Health\ReadinessProbe;
use Illuminate\Support\ServiceProvider;

class SharedServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(ReadinessCheck::class, ReadinessProbe::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
