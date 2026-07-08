<?php

arch('Platform does not depend on Audit, AiCore, People, Patients, or Scheduling')
    ->expect('Modules\Platform')
    ->not->toUse([
        'Modules\Audit',
        'Modules\AiCore',
        'Modules\People',
        'Modules\Patients',
        'Modules\Scheduling',
    ]);

arch('Audit does not depend on Platform, AiCore, People, Patients, or Scheduling')
    ->expect('Modules\Audit')
    ->not->toUse([
        'Modules\Platform',
        'Modules\AiCore',
        'Modules\People',
        'Modules\Patients',
        'Modules\Scheduling',
    ]);

arch('AiCore does not depend on Platform, Audit, People, Patients, or Scheduling')
    ->expect('Modules\AiCore')
    ->not->toUse([
        'Modules\Platform',
        'Modules\Audit',
        'Modules\People',
        'Modules\Patients',
        'Modules\Scheduling',
    ]);

arch('People does not depend on Audit, AiCore, Patients, or Scheduling')
    ->expect('Modules\People')
    ->not->toUse([
        'Modules\Audit',
        'Modules\AiCore',
        'Modules\Patients',
        'Modules\Scheduling',
    ]);

arch('Patients does not depend on Audit models, AiCore, or Scheduling')
    ->expect('Modules\Patients')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Scheduling',
    ]);

arch('Scheduling does not depend on Audit models or AiCore')
    ->expect('Modules\Scheduling')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
    ]);
