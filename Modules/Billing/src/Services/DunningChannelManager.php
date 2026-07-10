<?php

namespace Modules\Billing\Services;

use InvalidArgumentException;
use Modules\Billing\Channels\EmailDunningChannel;
use Modules\Billing\Contracts\DunningChannel;

class DunningChannelManager
{
    /**
     * @var array<string, DunningChannel>
     */
    private array $channels;

    public function __construct(EmailDunningChannel $email)
    {
        $this->channels = [$email->key() => $email];
    }

    public function has(string $channel): bool
    {
        return array_key_exists($channel, $this->channels);
    }

    public function get(string $channel): DunningChannel
    {
        return $this->channels[$channel] ?? throw new InvalidArgumentException("Dunning channel {$channel} is not configured.");
    }
}
