<?php

use App\Providers\AppServiceProvider;
use App\Providers\LicensingServiceProvider;
use App\Providers\LifecycleServiceProvider;
use App\Providers\RequestContextServiceProvider;

return [
    AppServiceProvider::class,
    LicensingServiceProvider::class,
    LifecycleServiceProvider::class,
    RequestContextServiceProvider::class,
];
