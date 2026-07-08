<?php

return [
    'provider' => env('AICORE_PROVIDER', 'anthropic'),
    'region' => env('AICORE_REGION', 'eu'),
    'timeout_seconds' => (int) env('AICORE_TIMEOUT_SECONDS', 10),
    'retries' => (int) env('AICORE_RETRIES', 1),
    'circuit_failure_threshold' => (int) env('AICORE_CIRCUIT_FAILURE_THRESHOLD', 3),
    'circuit_open_seconds' => (int) env('AICORE_CIRCUIT_OPEN_SECONDS', 300),
    'default_monthly_budget_minor' => (int) env('AICORE_DEFAULT_MONTHLY_BUDGET_MINOR', 5000),
    'providers' => [
        'anthropic' => [
            'endpoint' => env('ANTHROPIC_ENDPOINT', 'https://api.anthropic.com/v1/messages'),
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5'),
            'model_version' => env('ANTHROPIC_MODEL_VERSION', '2026-07-08'),
            'version_header' => env('ANTHROPIC_VERSION_HEADER', '2023-06-01'),
        ],
    ],
];
