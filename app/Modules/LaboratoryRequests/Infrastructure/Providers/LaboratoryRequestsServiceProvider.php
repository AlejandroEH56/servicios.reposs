<?php

namespace App\Modules\LaboratoryRequests\Infrastructure\Providers;

use App\Modules\LaboratoryRequests\Application\RequestCapture;
use App\Modules\LaboratoryRequests\Infrastructure\Persistence\RequestStore;
use Illuminate\Support\ServiceProvider;

class LaboratoryRequestsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RequestCapture::class, RequestStore::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/Routes/api_v1.php');
    }
}
