<?php

arch('Platform does not depend on Audit, AiCore, People, Patients, Scheduling, Clinical, Nursing, Billing, or Comms')
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
        'Modules\Comms',
    ]);

arch('Audit does not depend on Platform, AiCore, People, Patients, Scheduling, Clinical, Nursing, Billing, or Comms')
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
        'Modules\Comms',
    ]);

arch('AiCore may depend on Platform but not Audit, People, Patients, Scheduling, Clinical, Nursing, Billing, or Comms')
    ->expect('Modules\AiCore')
    ->not->toUse([
        'Modules\Audit',
        'Modules\People',
        'Modules\Patients',
        'Modules\Scheduling',
        'Modules\Clinical',
        'Modules\Nursing',
        'Modules\Billing',
        'Modules\Comms',
    ]);

arch('People does not depend on Audit, AiCore, Patients, Scheduling, Clinical, Nursing, Billing, or Comms')
    ->expect('Modules\People')
    ->not->toUse([
        'Modules\Audit',
        'Modules\AiCore',
        'Modules\Patients',
        'Modules\Scheduling',
        'Modules\Clinical',
        'Modules\Nursing',
        'Modules\Billing',
        'Modules\Comms',
    ]);

arch('Patients does not depend on Audit models, AiCore, Scheduling, Clinical, Nursing, Billing, or Comms')
    ->expect('Modules\Patients')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Scheduling',
        'Modules\Clinical',
        'Modules\Nursing',
        'Modules\Billing',
        'Modules\Comms',
    ]);

arch('Scheduling does not depend on Audit models, AiCore, Clinical, Nursing, Billing, or Comms')
    ->expect('Modules\Scheduling')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Clinical',
        'Modules\Nursing',
        'Modules\Billing',
        'Modules\Comms',
    ]);

arch('Clinical may use care modules but not Audit models, AiCore, Nursing, Billing, or Comms')
    ->expect('Modules\Clinical')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Nursing',
        'Modules\Billing',
        'Modules\Comms',
    ]);

arch('Nursing may use care modules but not Audit models, AiCore, Billing, or Comms')
    ->expect('Modules\Nursing')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Billing',
        'Modules\Comms',
    ]);

arch('Billing may use care modules but not Audit models, AiCore, or Comms')
    ->expect('Modules\Billing')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Comms',
    ]);

arch('Comms may use care modules but not Audit models or AiCore')
    ->expect('Modules\Comms')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
    ]);

arch('Import may use Patients + Audit services but not Audit models, AiCore, Scheduling, Clinical, Nursing, Billing, or Comms')
    ->expect('Modules\Import')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Scheduling',
        'Modules\Clinical',
        'Modules\Nursing',
        'Modules\Billing',
        'Modules\Comms',
    ]);
