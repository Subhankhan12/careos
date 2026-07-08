<?php

arch('Platform does not depend on Audit, AiCore, or People')
    ->expect('Modules\Platform')
    ->not->toUse([
        'Modules\Audit',
        'Modules\AiCore',
        'Modules\People',
    ]);

arch('Audit does not depend on Platform, AiCore, or People')
    ->expect('Modules\Audit')
    ->not->toUse([
        'Modules\Platform',
        'Modules\AiCore',
        'Modules\People',
    ]);

arch('AiCore does not depend on Platform, Audit, or People')
    ->expect('Modules\AiCore')
    ->not->toUse([
        'Modules\Platform',
        'Modules\Audit',
        'Modules\People',
    ]);

arch('People does not depend on Audit or AiCore')
    ->expect('Modules\People')
    ->not->toUse([
        'Modules\Audit',
        'Modules\AiCore',
    ]);
