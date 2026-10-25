<?php

namespace App\Modules\Residencies\Infrastructure\Providers;

use App\Modules\Residencies\Application\EvidenceWorkflow;
use App\Modules\Residencies\Application\ResidencyDirectory;
use App\Modules\Residencies\Infrastructure\Persistence\EvidenceStore;
use App\Modules\Residencies\Infrastructure\Persistence\ResidencyStore;
use Illuminate\Support\ServiceProvider;

class ResidenciesServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(ResidencyDirectory::class, ResidencyStore::class);
        $this->app->bind(EvidenceWorkflow::class, EvidenceStore::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/Routes/api_v1.php');
    }
}
