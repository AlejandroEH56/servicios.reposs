<?php

namespace App\Modules\Publications\Infrastructure\Providers;

use App\Modules\Publications\Application\Ports\NoticeImages;
use App\Modules\Publications\Application\Ports\NoticeRepository;
use App\Modules\Publications\Infrastructure\Persistence\DatabaseNotices;
use App\Modules\Publications\Infrastructure\Storage\PrivateNoticeImages;
use Illuminate\Support\ServiceProvider;

class PublicationsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(NoticeRepository::class, DatabaseNotices::class);
        $this->app->bind(NoticeImages::class, PrivateNoticeImages::class);
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
