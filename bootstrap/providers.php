<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use Modules\AiCore\Providers\AiCoreServiceProvider;
use Modules\Audit\Providers\AuditServiceProvider;
use Modules\Clinical\Providers\ClinicalServiceProvider;
use Modules\Nursing\Providers\NursingServiceProvider;
use Modules\Patients\Providers\PatientsServiceProvider;
use Modules\People\Providers\PeopleServiceProvider;
use Modules\Platform\Providers\PlatformServiceProvider;
use Modules\Scheduling\Providers\SchedulingServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    AiCoreServiceProvider::class,
    AuditServiceProvider::class,
    ClinicalServiceProvider::class,
    NursingServiceProvider::class,
    PatientsServiceProvider::class,
    PeopleServiceProvider::class,
    PlatformServiceProvider::class,
    SchedulingServiceProvider::class,
];
