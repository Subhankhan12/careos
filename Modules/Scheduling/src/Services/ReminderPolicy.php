<?php

namespace Modules\Scheduling\Services;

use Modules\Platform\Services\SettingsService;
use Modules\Scheduling\Models\AppointmentReminder;

class ReminderPolicy
{
    public const SETTING_KEY = 'scheduling.reminders.policy';

    /**
     * @var array{offset_minutes: list<int>, channels: list<string>}
     */
    private const DEFAULT_POLICY = [
        'offset_minutes' => [1440, 60],
        'channels' => [AppointmentReminder::CHANNEL_EMAIL],
    ];

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @return list<int>
     */
    public function offsetMinutes(): array
    {
        $policy = $this->policy();
        $offsets = array_map('intval', (array) ($policy['offset_minutes'] ?? self::DEFAULT_POLICY['offset_minutes']));
        $offsets = array_values(array_filter($offsets, fn (int $offset): bool => $offset > 0));
        rsort($offsets);

        return $offsets !== [] ? $offsets : self::DEFAULT_POLICY['offset_minutes'];
    }

    /**
     * @return list<string>
     */
    public function channels(): array
    {
        $policy = $this->policy();
        $channels = array_values(array_filter(
            array_map('strval', (array) ($policy['channels'] ?? self::DEFAULT_POLICY['channels'])),
            fn (string $channel): bool => trim($channel) !== '',
        ));

        return $channels !== [] ? $channels : self::DEFAULT_POLICY['channels'];
    }

    public function typeForOffset(int $minutes): string
    {
        return match ($minutes) {
            1440 => 'before_24h',
            60 => 'before_1h',
            default => 'before_'.$minutes.'m',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function policy(): array
    {
        $value = $this->settings->get(self::SETTING_KEY, self::DEFAULT_POLICY);

        return is_array($value) ? $value : self::DEFAULT_POLICY;
    }
}
