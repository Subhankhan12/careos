<?php

namespace Modules\Comms\Providers\Telehealth;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Modules\Comms\Contracts\TelehealthProvider;
use Modules\Comms\Services\TelehealthToken;
use RuntimeException;

/**
 * LiveKit adapter (D-G1 default vendor). Media stays on LiveKit's SFU — never
 * on CareOS servers. Keys come from config/env only and are NEVER logged.
 *
 * D-G2 — recording disabled AT THE PROVIDER: the room is created with no
 * egress configuration, and every token we mint carries
 * `roomRecord: false` + `roomAdmin: false`, so no participant can start
 * recording or egress. Recording/transcripts are DEFERRED behind a funded
 * consent + retention design.
 */
class LiveKitProvider implements TelehealthProvider
{
    public function name(): string
    {
        return 'livekit';
    }

    public function createRoom(string $roomName, array $options): string
    {
        $this->assertRecordingDisabled($options);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])->post(rtrim((string) config('telehealth.providers.livekit.host'), '/').'/twirp/livekit.RoomService/CreateRoom', [
            'name' => $roomName,
            'empty_timeout' => 600,
            'max_participants' => (int) ($options['max_participants'] ?? 2),
            // No egress block: recording/egress is structurally unconfigured.
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Telehealth room creation failed.');
        }

        return (string) ($response->json('name') ?? $roomName);
    }

    public function issueToken(string $roomReference, string $identity, string $role, int $ttlSeconds): TelehealthToken
    {
        $grants = $this->grantsFor($roomReference, $role);
        $now = CarbonImmutable::now();
        $expiresAt = $now->addSeconds($ttlSeconds);

        $payload = [
            'iss' => (string) config('telehealth.providers.livekit.api_key'),
            'sub' => $identity,
            'nbf' => $now->getTimestamp(),
            'exp' => $expiresAt->getTimestamp(),
            'video' => $grants,
        ];

        return new TelehealthToken(
            token: $this->jwt($payload),
            roomReference: $roomReference,
            identity: $identity,
            role: $role,
            grants: $grants,
            ttlSeconds: $ttlSeconds,
            expiresAt: $expiresAt,
        );
    }

    public function endRoom(string $roomReference): void
    {
        Http::withHeaders([
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])->post(rtrim((string) config('telehealth.providers.livekit.host'), '/').'/twirp/livekit.RoomService/DeleteRoom', [
            'room' => $roomReference,
        ]);
    }

    /**
     * One room, one identity, one role. Staff and patients may publish and
     * subscribe; NOBODY may record or administer the room.
     *
     * @return array<string, mixed>
     */
    private function grantsFor(string $roomReference, string $role): array
    {
        if (! in_array($role, ['staff', 'patient'], true)) {
            throw new InvalidArgumentException('Unsupported telehealth role.');
        }

        return [
            'room' => $roomReference,
            'roomJoin' => true,
            'canPublish' => true,
            'canSubscribe' => true,
            'canPublishData' => true,
            'roomRecord' => false,
            'roomAdmin' => false,
            'recorder' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function assertRecordingDisabled(array $options): void
    {
        if (($options['recording_disabled'] ?? false) !== true) {
            throw new InvalidArgumentException('Telehealth rooms must be created with recording disabled (D-G2).');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function jwt(array $payload): string
    {
        $secret = (string) config('telehealth.providers.livekit.api_secret');

        if ($secret === '') {
            throw new RuntimeException('Telehealth provider secret is not configured.');
        }

        $encode = fn (array $data): string => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');
        $header = $encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $body = $encode($payload);
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $header.'.'.$body, $secret, true)), '+/', '-_'), '=');

        return $header.'.'.$body.'.'.$signature;
    }

    private function adminToken(): string
    {
        return $this->jwt([
            'iss' => (string) config('telehealth.providers.livekit.api_key'),
            'sub' => 'careos-server',
            'nbf' => CarbonImmutable::now()->getTimestamp(),
            'exp' => CarbonImmutable::now()->addSeconds(60)->getTimestamp(),
            'video' => ['roomCreate' => true, 'roomList' => true],
        ]);
    }
}
