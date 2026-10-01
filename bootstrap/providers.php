<?php

declare(strict_types=1);
use App\Providers\AccessServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\TenancyServiceProvider;
use App\Providers\WhatsAppServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    WhatsAppServiceProvider::class,
    AccessServiceProvider::class,
    HorizonServiceProvider::class,
];
