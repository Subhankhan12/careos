<?php

/*
 * Telehealth (D-G1/G2/G3): embedded third-party WebRTC behind a swappable
 * adapter. Media never passes through or rests on CareOS servers; recording is
 * disabled at the provider level; the room is NOT the clinical record.
 * Credentials come from env only and are NEVER logged.
 */
return [
    'provider' => env('TELEHEALTH_PROVIDER', 'livekit'),

    // Hard ceiling for join-token TTL (seconds). D-G1: short-lived tokens.
    'max_token_ttl_seconds' => (int) env('TELEHEALTH_MAX_TOKEN_TTL', 600),

    'providers' => [
        'livekit' => [
            'host' => env('LIVEKIT_HOST', 'https://livekit.invalid'),
            'api_key' => env('LIVEKIT_API_KEY', ''),
            'api_secret' => env('LIVEKIT_API_SECRET', ''),
        ],
    ],
];
