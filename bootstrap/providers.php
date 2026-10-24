<?php

use App\Modules\AcademicPlanning\Infrastructure\Providers\AcademicPlanningServiceProvider;
use App\Modules\IAM\Infrastructure\Providers\IamServiceProvider;
use App\Modules\Organization\Infrastructure\Providers\OrganizationServiceProvider;
use App\Modules\Publications\Infrastructure\Providers\PublicationsServiceProvider;
use App\Modules\Residencies\Infrastructure\Providers\ResidenciesServiceProvider;
use App\Modules\Shared\Infrastructure\Providers\SharedServiceProvider;
use App\Providers\AppServiceProvider;

return [
    IamServiceProvider::class,
    OrganizationServiceProvider::class,
    SharedServiceProvider::class,
    AppServiceProvider::class,
    PublicationsServiceProvider::class,
    AcademicPlanningServiceProvider::class,
    ResidenciesServiceProvider::class,
];
