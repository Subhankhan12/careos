<?php

use App\Jobs\QueueSanityJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;

uses(RefreshDatabase::class);

function configureRedisQueueForGateC0(): void
{
    config([
        'cache.default' => 'redis',
        'database.redis.client' => 'predis',
        'database.redis.default.host' => env('REDIS_HOST', '127.0.0.1'),
        'database.redis.default.port' => env('REDIS_PORT', '6379'),
        'database.redis.cache.host' => env('REDIS_HOST', '127.0.0.1'),
        'database.redis.cache.port' => env('REDIS_PORT', '6379'),
        'queue.default' => 'redis',
        'queue.connections.redis.connection' => 'default',
    ]);

    app('cache')->setDefaultDriver('redis');
    app('queue')->setDefaultDriver('redis');
}

function redisIsReachableForGateC0(): bool
{
    try {
        Redis::connection('default')->command('PING');

        return true;
    } catch (Throwable) {
        return false;
    }
}

test('redis queue connection is configured and a sanity job runs round trip', function () {
    configureRedisQueueForGateC0();

    if (! redisIsReachableForGateC0()) {
        $this->markTestSkipped('Redis is not reachable on 127.0.0.1:6379.');
    }

    $queue = 'careos-c0-test-'.Str::lower(Str::random(8));
    $key = 'careos:c0:'.(string) Str::uuid();
    $value = 'ran';

    config(['queue.connections.redis.queue' => $queue]);
    Cache::store('redis')->forget($key);

    QueueSanityJob::dispatch($key, $value)
        ->onConnection('redis')
        ->onQueue($queue);

    Artisan::call('queue:work', [
        'connection' => 'redis',
        '--queue' => $queue,
        '--once' => true,
        '--tries' => 1,
    ]);

    expect(config('queue.default'))->toBe('redis')
        ->and(config('cache.default'))->toBe('redis')
        ->and(Cache::store('redis')->get($key))->toBe($value);
});

test('horizon dashboard is guarded to super admins only', function () {
    $tenant = Tenant::create([
        'name' => 'Alpha Clinic',
        'slug' => 'alpha-horizon',
        'region' => 'eu',
        'status' => 'active',
    ]);
    $staff = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    $admin = User::factory()->twoFactorEnabled()->create();

    $this->get('/horizon')->assertRedirect('/login');
    $this->actingAs($staff)->get('/horizon')->assertForbidden();
    $this->actingAs($admin)->get('/horizon')->assertOk();
});
