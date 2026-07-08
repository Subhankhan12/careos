<?php

namespace Modules\AiCore\Services;

use Modules\Platform\Services\SettingsService;

class KillSwitch
{
    public function __construct(private readonly SettingsService $settings) {}

    public function enabled(string $feature): bool
    {
        return (bool) $this->settings->get('ai.feature.'.$feature.'.enabled', true);
    }

    public function disable(string $feature): void
    {
        $this->settings->set('ai.feature.'.$feature.'.enabled', false, 'bool');
    }
}
