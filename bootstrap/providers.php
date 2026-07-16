<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use Modules\AiCore\Providers\AiCoreServiceProvider;
use Modules\Audit\Providers\AuditServiceProvider;
use Modules\Billing\Providers\BillingServiceProvider;
use Modules\Clinical\Providers\ClinicalServiceProvider;
use Modules\Comms\Providers\CommsServiceProvider;
use Modules\Import\Providers\ImportServiceProvider;
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
    BillingServiceProvider::class,
    ClinicalServiceProvider::class,
    CommsServiceProvider::class,
    ImportServiceProvider::class,
    NursingServiceProvider::class,
    PatientsServiceProvider::class,
    PeopleServiceProvider::class,
    PlatformServiceProvider::class,
    SchedulingServiceProvider::class,
];
