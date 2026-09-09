<?php

use Database\Seeders\Concerns\RefusesOutsideDevelopment;
use Database\Seeders\DemoDentalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;

uses(RefreshDatabase::class);

/*
 * QA-FIX.9b — `P10-C1`: demo seeders refuse to run in production by their own guard, and the framework
 * skeleton's platform super-admin is confined to a disposable database.
 *
 * DEPLOY.PROV already pins that the production seed path cannot REACH a demo seeder
 * (ProvisioningCommandsTest, "the production seed path cannot reach a demo seeder"). That test is left
 * exactly as it is — it asserts the WIRING. These tests assert the REFUSAL, which is what survives a
 * future edit to that wiring. They also cover what DEPLOY.PROV structurally could not see: it asserts
 * `Tenant::count() === 0`, and a platform super-admin has no tenant, so the account was created while the
 * assertion passed.
 */

/** Pretend to be an environment, run something, and put the environment back whatever happens. */
function dsgInEnvironment(string $environment, callable $body): mixed
{
    $original = app()->environment();
    app()->detectEnvironment(fn (): string => $environment);

    try {
        return $body();
    } finally {
        app()->detectEnvironment(fn (): string => $original);
    }
}

/** A minimal user of the trait, so the guard itself is tested rather than a 30-second demo seed. */
function dsgGuard(): object
{
    return new class
    {
        use RefusesOutsideDevelopment;

        public function check(): void
        {
            $this->assertDisposableEnvironment();
        }

        public function disposable(): bool
        {
            return $this->isDisposableEnvironment();
        }
    };
}

it('refuses to seed demo data in production, and creates nothing', function () {
    // BEFORE THE FIX this call seeded a whole fake clinic. The guard runs before any write.
    dsgInEnvironment('production', function () {
        expect(fn () => app(DemoDentalSeeder::class)->run())
            ->toThrow(RuntimeException::class, 'refuses to run in the "production" environment');
    });

    expect(Tenant::query()->count())->toBe(0);
});

it('refuses in an environment that merely is not production, because the list is an allow-list', function () {
    foreach (['staging', 'demo', 'uat', 'prod'] as $environment) {
        dsgInEnvironment($environment, function () use ($environment) {
            expect(fn () => dsgGuard()->check())
                ->toThrow(RuntimeException::class, sprintf('refuses to run in the "%s" environment', $environment));
        });
    }
});

it('still permits the environments the audit and CI actually use', function () {
    // THE POSITIVE CONTROL (D-174): a guard that refused everywhere would pass the tests above and make
    // the product unseedable for development.
    foreach (['local', 'testing'] as $environment) {
        dsgInEnvironment($environment, function () {
            dsgGuard()->check(); // must not throw
            expect(dsgGuard()->disposable())->toBeTrue();
        });
    }
});

it('pins that every demo seeder calls the guard before it writes anything', function () {
    $seeders = ['DemoClinicSeeder', 'DemoSpitexSeeder', 'DemoDentalSeeder', 'DemoHospitalSeeder'];
    $checked = 0;

    foreach ($seeders as $seeder) {
        $file = base_path("database/seeders/{$seeder}.php");

        // Comment-stripped: every one of these files explains the guard in prose that names it.
        $source = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $source .= $token[1];

                continue;
            }
            $source .= $token;
        }

        $this->assertStringContainsString('use RefusesOutsideDevelopment;', $source, "{$seeder} does not use the guard trait.");

        // The guard must be the FIRST statement of run(), before any write.
        $run = substr($source, (int) strpos($source, 'public function run(): void'));
        $firstStatement = trim(explode(';', substr($run, (int) strpos($run, '{') + 1), 2)[0]);
        expect($firstStatement)->toBe('$this->assertDisposableEnvironment()', "{$seeder} does not guard first.");

        $checked++;
    }

    // The scan must not pass by finding nothing (D-174).
    expect($checked)->toBe(4);
});

it('does not create the skeleton platform super-admin outside a disposable database', function () {
    // `db:seed --force` is a documented production deploy step, and UserFactory's default state is
    // tenant_id = null — a PLATFORM SUPER-ADMIN — with the skeleton's published password.
    dsgInEnvironment('production', function () {
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
    });

    expect(User::query()->whereNull('tenant_id')->count())->toBe(0)
        ->and(User::query()->where('email', 'test@example.com')->exists())->toBeFalse();
});

it('still creates it where the database is disposable, so development is unchanged', function () {
    // THE POSITIVE CONTROL for the half above: local and testing keep the account they always had.
    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

    expect(User::query()->where('email', 'test@example.com')->exists())->toBeTrue();
});
