<?php

arch('Platform does not depend on Audit, AiCore, People, Patients, Scheduling, Clinical, Nursing, or Billing')
    ->expect('Modules\Platform')
    ->not->toUse([
        'Modules\Audit',
        'Modules\AiCore',
        'Modules\People',
        'Modules\Patients',
        'Modules\Scheduling',
        'Modules\Clinical',
        'Modules\Nursing',
        'Modules\Billing',
    ]);

arch('Audit does not depend on Platform, AiCore, People, Patients, Scheduling, Clinical, Nursing, or Billing')
    ->expect('Modules\Audit')
    ->not->toUse([
        'Modules\Platform',
        'Modules\AiCore',
        'Modules\People',
        'Modules\Patients',
        'Modules\Scheduling',
        'Modules\Clinical',
        'Modules\Nursing',
        'Modules\Billing',
    ]);

arch('AiCore may depend on Platform but not Audit, People, Patients, Scheduling, Clinical, Nursing, or Billing')
    ->expect('Modules\AiCore')
    ->not->toUse([
        'Modules\Audit',
        'Modules\People',
        'Modules\Patients',
        'Modules\Scheduling',
        'Modules\Clinical',
        'Modules\Nursing',
        'Modules\Billing',
    ]);

arch('People does not depend on Audit, AiCore, Patients, Scheduling, Clinical, Nursing, or Billing')
    ->expect('Modules\People')
    ->not->toUse([
        'Modules\Audit',
        'Modules\AiCore',
        'Modules\Patients',
        'Modules\Scheduling',
        'Modules\Clinical',
        'Modules\Nursing',
        'Modules\Billing',
    ]);

arch('Patients does not depend on Audit models, AiCore, Scheduling, Clinical, Nursing, or Billing')
    ->expect('Modules\Patients')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Scheduling',
        'Modules\Clinical',
        'Modules\Nursing',
        'Modules\Billing',
    ]);

arch('Scheduling does not depend on Audit models, AiCore, Clinical, Nursing, or Billing')
    ->expect('Modules\Scheduling')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Clinical',
        'Modules\Nursing',
        'Modules\Billing',
    ]);

arch('Clinical may use care modules but not Audit models, AiCore, Nursing, or Billing')
    ->expect('Modules\Clinical')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Nursing',
        'Modules\Billing',
    ]);

arch('Nursing may use care modules but not Audit models, AiCore, or Billing')
    ->expect('Modules\Nursing')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Billing',
    ]);

arch('Billing may use care modules but not Audit models or AiCore')
    ->expect('Modules\Billing')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
    ]);
