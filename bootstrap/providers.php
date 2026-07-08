<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use Modules\AiCore\Providers\AiCoreServiceProvider;
use Modules\Audit\Providers\AuditServiceProvider;
use Modules\Patients\Providers\PatientsServiceProvider;
use Modules\People\Providers\PeopleServiceProvider;
use Modules\Platform\Providers\PlatformServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    AiCoreServiceProvider::class,
    AuditServiceProvider::class,
    PatientsServiceProvider::class,
    PeopleServiceProvider::class,
    PlatformServiceProvider::class,
];
