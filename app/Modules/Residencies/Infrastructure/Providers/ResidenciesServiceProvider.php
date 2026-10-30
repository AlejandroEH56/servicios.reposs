<?php

namespace App\Modules\Residencies\Infrastructure\Providers;

use App\Modules\Residencies\Application\DocumentWorkflow;
use App\Modules\Residencies\Application\EvidenceWorkflow;
use App\Modules\Residencies\Application\Ports\DocumentRenderer;
use App\Modules\Residencies\Application\ProgressWorkflow;
use App\Modules\Residencies\Application\ResidencyDirectory;
use App\Modules\Residencies\Infrastructure\Documents\DompdfRenderer;
use App\Modules\Residencies\Infrastructure\Persistence\DocumentStore;
use App\Modules\Residencies\Infrastructure\Persistence\EvidenceStore;
use App\Modules\Residencies\Infrastructure\Persistence\ProgressStore;
use App\Modules\Residencies\Infrastructure\Persistence\ResidencyStore;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
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
        $this->app->bind(ProgressWorkflow::class, ProgressStore::class);
        $this->app->bind(DocumentWorkflow::class, DocumentStore::class);
        $this->app->bind(DocumentRenderer::class, DompdfRenderer::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Queue::looping(function (Looping $event): void {
            if ($event->connectionName === 'documents' && $event->queue === 'documents') {
                Cache::put('residencies:documents:heartbeat', now()->timestamp, 240);
            }
        });
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/Routes/api_v1.php');
    }
}
