<?php

namespace Modules\AiCore\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\Platform\Services\TenantContext;

class CircuitBreaker
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function assertClosed(string $provider, string $feature): void
    {
        $openUntil = Cache::get($this->key($provider, $feature, 'open_until'));

        if (is_string($openUntil) && Carbon::parse($openUntil)->isFuture()) {
            throw new AiCoreException('AI provider circuit is open; route to manual workflow.');
        }
    }

    public function recordSuccess(string $provider, string $feature): void
    {
        Cache::forget($this->key($provider, $feature, 'failures'));
        Cache::forget($this->key($provider, $feature, 'open_until'));
    }

    public function recordFailure(string $provider, string $feature): void
    {
        $failures = (int) Cache::increment($this->key($provider, $feature, 'failures'));
        $threshold = (int) config('aicore.circuit_failure_threshold', 3);

        if ($failures >= $threshold) {
            Cache::put(
                $this->key($provider, $feature, 'open_until'),
                Carbon::now()->addSeconds((int) config('aicore.circuit_open_seconds', 300))->toIso8601String(),
                (int) config('aicore.circuit_open_seconds', 300),
            );
        }
    }

    private function key(string $provider, string $feature, string $suffix): string
    {
        return 'aicore:circuit:'.$this->tenantContext->id().':'.$provider.':'.$feature.':'.$suffix;
    }
}
