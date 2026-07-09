<?php

arch('Platform does not depend on Audit, AiCore, People, Patients, Scheduling, Clinical, or Nursing')
    ->expect('Modules\Platform')
    ->not->toUse([
        'Modules\Audit',
        'Modules\AiCore',
        'Modules\People',
        'Modules\Patients',
        'Modules\Scheduling',
        'Modules\Clinical',
        'Modules\Nursing',
    ]);

arch('Audit does not depend on Platform, AiCore, People, Patients, Scheduling, Clinical, or Nursing')
    ->expect('Modules\Audit')
    ->not->toUse([
        'Modules\Platform',
        'Modules\AiCore',
        'Modules\People',
        'Modules\Patients',
        'Modules\Scheduling',
        'Modules\Clinical',
        'Modules\Nursing',
    ]);

arch('AiCore may depend on Platform but not Audit, People, Patients, Scheduling, Clinical, or Nursing')
    ->expect('Modules\AiCore')
    ->not->toUse([
        'Modules\Audit',
        'Modules\People',
        'Modules\Patients',
        'Modules\Scheduling',
        'Modules\Clinical',
        'Modules\Nursing',
    ]);

arch('People does not depend on Audit, AiCore, Patients, Scheduling, Clinical, or Nursing')
    ->expect('Modules\People')
    ->not->toUse([
        'Modules\Audit',
        'Modules\AiCore',
        'Modules\Patients',
        'Modules\Scheduling',
        'Modules\Clinical',
        'Modules\Nursing',
    ]);

arch('Patients does not depend on Audit models, AiCore, Scheduling, Clinical, or Nursing')
    ->expect('Modules\Patients')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Scheduling',
        'Modules\Clinical',
        'Modules\Nursing',
    ]);

arch('Scheduling does not depend on Audit models, AiCore, Clinical, or Nursing')
    ->expect('Modules\Scheduling')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Clinical',
        'Modules\Nursing',
    ]);

arch('Clinical may use care modules but not Audit models, AiCore, or Nursing')
    ->expect('Modules\Clinical')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Nursing',
    ]);

arch('Nursing may use care modules but not Audit models or AiCore')
    ->expect('Modules\Nursing')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
    ]);
