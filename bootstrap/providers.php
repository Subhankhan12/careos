<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use Modules\AiCore\Providers\AiCoreServiceProvider;
use Modules\Audit\Providers\AuditServiceProvider;
use Modules\Platform\Providers\PlatformServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    PlatformServiceProvider::class,
    AuditServiceProvider::class,
    AiCoreServiceProvider::class,
];
