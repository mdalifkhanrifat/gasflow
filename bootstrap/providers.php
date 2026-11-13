<?php

use App\Providers\AppServiceProvider;
use App\Shared\Lifecycle\LifecycleServiceProvider;
use App\Shared\RequestContext\RequestContextServiceProvider;

return [
    AppServiceProvider::class,
    LifecycleServiceProvider::class,
    RequestContextServiceProvider::class,
];
