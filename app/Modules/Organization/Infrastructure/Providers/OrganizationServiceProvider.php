<?php

namespace App\Modules\Organization\Infrastructure\Providers;

use App\Modules\Organization\Application\OrganizationDirectory;
use App\Modules\Organization\Application\ProfileProjector;
use App\Modules\Organization\Infrastructure\Persistence\OrganizationStore;
use App\Modules\Organization\Infrastructure\Persistence\ProjectInstitutionalProfile;
use App\Modules\Organization\Presentation\Http\Policies\EmployeePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class OrganizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrganizationDirectory::class, OrganizationStore::class);
        $this->app->bind(ProfileProjector::class, ProjectInstitutionalProfile::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
        Gate::define('organization.employee.view', [EmployeePolicy::class, 'view']);
        Gate::define('organization.employee.degrees', [EmployeePolicy::class, 'degrees']);
        Gate::define('organization.capability', [EmployeePolicy::class, 'capability']);
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/Routes/api_v1.php');
    }
}
