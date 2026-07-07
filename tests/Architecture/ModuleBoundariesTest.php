<?php

arch('Platform does not depend on Audit or AiCore')
    ->expect('Modules\Platform')
    ->not->toUse([
        'Modules\Audit',
        'Modules\AiCore',
    ]);

arch('Audit does not depend on Platform or AiCore')
    ->expect('Modules\Audit')
    ->not->toUse([
        'Modules\Platform',
        'Modules\AiCore',
    ]);

arch('AiCore does not depend on Platform or Audit')
    ->expect('Modules\AiCore')
    ->not->toUse([
        'Modules\Platform',
        'Modules\Audit',
    ]);
