<?php

namespace App\Modules\IAM\Infrastructure\Providers;

use App\Modules\IAM\Application\EntraAuthenticator;
use App\Modules\IAM\Application\IdentityProvisioner;
use App\Modules\IAM\Infrastructure\Entra\EntraOidcClient;
use App\Modules\IAM\Infrastructure\Persistence\ProvisionIdentity;
use Illuminate\Support\ServiceProvider;

class IamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EntraAuthenticator::class, EntraOidcClient::class);
        $this->app->bind(IdentityProvisioner::class, ProvisionIdentity::class);
    }
}
