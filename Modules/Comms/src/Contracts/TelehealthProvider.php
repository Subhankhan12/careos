<?php

namespace Modules\Comms\Contracts;

use Modules\Comms\Services\TelehealthToken;

/**
 * Swappable telehealth adapter (D-G1). Media NEVER passes through or rests on
 * CareOS servers — the provider hosts the WebRTC session; CareOS stores only
 * the room reference, participants, and join/leave timestamps.
 *
 * D-G2: rooms are created with recording DISABLED at the provider level (the
 * room options make recording impossible to initiate), not merely "we don't
 * call the record API". D-G3: the room is NOT the clinical record — no
 * transcript, no audio capture, no AI listening, ever (ELECTRIC FENCE).
 */
interface TelehealthProvider
{
    public function name(): string;

    /**
     * Create a room. $options MUST carry `recording_disabled => true`; an
     * adapter must refuse to create a room without it.
     *
     * @param  array<string, mixed>  $options
     * @return string the provider's room reference
     */
    public function createRoom(string $roomName, array $options): string;

    /**
     * Issue a SHORT-LIVED join token scoped to exactly one room, one identity,
     * one role. No role may ever carry a recording grant.
     */
    public function issueToken(string $roomReference, string $identity, string $role, int $ttlSeconds): TelehealthToken;

    public function endRoom(string $roomReference): void;
}
