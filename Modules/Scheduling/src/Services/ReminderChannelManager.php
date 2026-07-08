<?php

namespace Modules\Scheduling\Services;

use InvalidArgumentException;
use Modules\Scheduling\Channels\EmailAppointmentReminderChannel;
use Modules\Scheduling\Contracts\AppointmentReminderChannel;

class ReminderChannelManager
{
    /**
     * @var array<string, AppointmentReminderChannel>
     */
    private array $channels;

    public function __construct(EmailAppointmentReminderChannel $email)
    {
        $this->channels = [$email->key() => $email];
    }

    public function has(string $channel): bool
    {
        return array_key_exists($channel, $this->channels);
    }

    public function get(string $channel): AppointmentReminderChannel
    {
        return $this->channels[$channel] ?? throw new InvalidArgumentException("Reminder channel {$channel} is not configured.");
    }
}
