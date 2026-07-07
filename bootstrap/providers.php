<?php

use App\Providers\AppServiceProvider;
use Modules\AiCore\Providers\AiCoreServiceProvider;
use Modules\Audit\Providers\AuditServiceProvider;
use Modules\Platform\Providers\PlatformServiceProvider;

return [
    AppServiceProvider::class,
    PlatformServiceProvider::class,
    AuditServiceProvider::class,
    AiCoreServiceProvider::class,
];
