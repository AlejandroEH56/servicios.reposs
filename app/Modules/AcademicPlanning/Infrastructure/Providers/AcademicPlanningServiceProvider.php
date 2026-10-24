<?php

namespace App\Modules\AcademicPlanning\Infrastructure\Providers;

use App\Modules\AcademicPlanning\Application\LaboratoryCalendar;
use App\Modules\AcademicPlanning\Application\PlanningDirectory;
use App\Modules\AcademicPlanning\Infrastructure\Persistence\LaboratoryCalendarStore;
use App\Modules\AcademicPlanning\Infrastructure\Persistence\PlanningStore;
use Illuminate\Support\ServiceProvider;

class AcademicPlanningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PlanningDirectory::class, PlanningStore::class);
        $this->app->bind(LaboratoryCalendar::class, LaboratoryCalendarStore::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/Routes/api_v1.php');
    }
}
