<?php

arch('Platform does not depend on Audit, AiCore, People, Patients, Scheduling, or Clinical')
    ->expect('Modules\Platform')
    ->not->toUse([
        'Modules\Audit',
        'Modules\AiCore',
        'Modules\People',
        'Modules\Patients',
        'Modules\Scheduling',
        'Modules\Clinical',
    ]);

arch('Audit does not depend on Platform, AiCore, People, Patients, Scheduling, or Clinical')
    ->expect('Modules\Audit')
    ->not->toUse([
        'Modules\Platform',
        'Modules\AiCore',
        'Modules\People',
        'Modules\Patients',
        'Modules\Scheduling',
        'Modules\Clinical',
    ]);

arch('AiCore may depend on Platform but not Audit, People, Patients, Scheduling, or Clinical')
    ->expect('Modules\AiCore')
    ->not->toUse([
        'Modules\Audit',
        'Modules\People',
        'Modules\Patients',
        'Modules\Scheduling',
        'Modules\Clinical',
    ]);

arch('People does not depend on Audit, AiCore, Patients, Scheduling, or Clinical')
    ->expect('Modules\People')
    ->not->toUse([
        'Modules\Audit',
        'Modules\AiCore',
        'Modules\Patients',
        'Modules\Scheduling',
        'Modules\Clinical',
    ]);

arch('Patients does not depend on Audit models, AiCore, Scheduling, or Clinical')
    ->expect('Modules\Patients')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Scheduling',
        'Modules\Clinical',
    ]);

arch('Scheduling does not depend on Audit models, AiCore, or Clinical')
    ->expect('Modules\Scheduling')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
        'Modules\Clinical',
    ]);

arch('Clinical may use care modules but not Audit models or AiCore')
    ->expect('Modules\Clinical')
    ->not->toUse([
        'Modules\Audit\Models',
        'Modules\AiCore',
    ]);
