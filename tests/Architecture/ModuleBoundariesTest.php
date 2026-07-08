<?php

arch('Platform does not depend on Audit, AiCore, People, or Patients')
    ->expect('Modules\Platform')
    ->not->toUse([
        'Modules\Audit',
        'Modules\AiCore',
        'Modules\People',
        'Modules\Patients',
    ]);

arch('Audit does not depend on Platform, AiCore, People, or Patients')
    ->expect('Modules\Audit')
    ->not->toUse([
        'Modules\Platform',
        'Modules\AiCore',
        'Modules\People',
        'Modules\Patients',
    ]);

arch('AiCore does not depend on Platform, Audit, People, or Patients')
    ->expect('Modules\AiCore')
    ->not->toUse([
        'Modules\Platform',
        'Modules\Audit',
        'Modules\People',
        'Modules\Patients',
    ]);

arch('People does not depend on Audit, AiCore, or Patients')
    ->expect('Modules\People')
    ->not->toUse([
        'Modules\Audit',
        'Modules\AiCore',
        'Modules\Patients',
    ]);

arch('Patients does not depend on Audit models or AiCore')
    ->expect('Modules\Patients')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
    ]);
