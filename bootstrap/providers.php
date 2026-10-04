<?php

use App\Modules\IAM\Infrastructure\Providers\IamServiceProvider;
use App\Modules\Shared\Infrastructure\Providers\SharedServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    SharedServiceProvider::class,
    IamServiceProvider::class,
];
