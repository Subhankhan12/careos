<?php

namespace Modules\Comms\Services;

use Carbon\CarbonImmutable;

/**
 * A short-lived, single-room, single-identity, single-role join token.
 * Tokens are returned to the caller on demand — never stored, never logged.
 */
class TelehealthToken
{
    /**
     * @param  array<string, mixed>  $grants
     */
    public function __construct(
        public readonly string $token,
        public readonly string $roomReference,
        public readonly string $identity,
        public readonly string $role,
        public readonly array $grants,
        public readonly int $ttlSeconds,
        public readonly CarbonImmutable $expiresAt,
    ) {}
}
