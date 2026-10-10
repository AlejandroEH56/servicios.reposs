<?php

namespace App\Modules\IAM\Infrastructure\Providers;

use App\Modules\IAM\Application\AuthorizationAdministration;
use App\Modules\IAM\Application\AuthorizationDirectory;
use App\Modules\IAM\Application\EntraAuthenticator;
use App\Modules\IAM\Application\IdentityDirectory;
use App\Modules\IAM\Application\IdentityProvisioner;
use App\Modules\IAM\Infrastructure\Entra\EntraOidcClient;
use App\Modules\IAM\Infrastructure\Persistence\AuthorizationStore;
use App\Modules\IAM\Infrastructure\Persistence\IdentityDirectoryStore;
use App\Modules\IAM\Infrastructure\Persistence\ProvisionIdentity;
use App\Modules\IAM\Presentation\Http\Policies\IamPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class IamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EntraAuthenticator::class, EntraOidcClient::class);
        $this->app->bind(IdentityProvisioner::class, ProvisionIdentity::class);
        $this->app->bind(AuthorizationDirectory::class, AuthorizationStore::class);
        $this->app->bind(AuthorizationAdministration::class, AuthorizationStore::class);
        $this->app->bind(IdentityDirectory::class, IdentityDirectoryStore::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
        Gate::define('iam.read', [IamPolicy::class, 'read']);
        Gate::define('iam.manage', [IamPolicy::class, 'manage']);
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/Routes/api_v1.php');
    }
}
