<?php

namespace Modules\Comms\Providers\Telehealth;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Comms\Contracts\TelehealthProvider;
use Modules\Comms\Services\TelehealthToken;

/**
 * In-memory provider for the test suite: no network calls, ever. Records the
 * exact room options and issued grants so tests can assert the D-G2 posture.
 */
class FakeTelehealthProvider implements TelehealthProvider
{
    /** @var list<array{name: string, options: array<string, mixed>}> */
    public array $createdRooms = [];

    /** @var list<array{room: string, identity: string, role: string, ttl: int}> */
    public array $issuedTokens = [];

    /** @var list<string> */
    public array $endedRooms = [];

    public function name(): string
    {
        return 'fake';
    }

    public function createRoom(string $roomName, array $options): string
    {
        if (($options['recording_disabled'] ?? false) !== true) {
            throw new InvalidArgumentException('Telehealth rooms must be created with recording disabled (D-G2).');
        }

        $this->createdRooms[] = ['name' => $roomName, 'options' => $options];

        return 'fake-room-'.$roomName;
    }

    public function issueToken(string $roomReference, string $identity, string $role, int $ttlSeconds): TelehealthToken
    {
        if (! in_array($role, ['staff', 'patient'], true)) {
            throw new InvalidArgumentException('Unsupported telehealth role.');
        }

        $this->issuedTokens[] = ['room' => $roomReference, 'identity' => $identity, 'role' => $role, 'ttl' => $ttlSeconds];

        $grants = [
            'room' => $roomReference,
            'roomJoin' => true,
            'canPublish' => true,
            'canSubscribe' => true,
            'canPublishData' => true,
            'roomRecord' => false,
            'roomAdmin' => false,
            'recorder' => false,
        ];

        return new TelehealthToken(
            token: 'fake-token-'.$identity,
            roomReference: $roomReference,
            identity: $identity,
            role: $role,
            grants: $grants,
            ttlSeconds: $ttlSeconds,
            expiresAt: CarbonImmutable::now()->addSeconds($ttlSeconds),
        );
    }

    public function endRoom(string $roomReference): void
    {
        $this->endedRooms[] = $roomReference;
    }
}
