<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\RefusesOutsideDevelopment;
use Illuminate\Database\Seeder;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Modules\Platform\Models\User;

class DatabaseSeeder extends Seeder
{
    use RefusesOutsideDevelopment;

    /**
     * Seed the application's database.
     *
     * THE PRODUCTION SEED PATH IS THE CATALOGS AND NOTHING ELSE. `php artisan db:seed --force` is a
     * documented deploy step (DEPLOY runbook §6), so whatever runs here runs on a customer's database.
     *
     * QA-FIX.9b (`P10-C1`, D-219) — the framework skeleton's convenience account used to be created
     * unconditionally, and it is not the harmless row it looks like: `UserFactory`'s default state is
     * `tenant_id = null`, which is a PLATFORM SUPER-ADMIN, and its password is the skeleton's published
     * `password`. Driven in Phase 10: signing in with those credentials succeeded and handed over
     * self-service 2FA enrolment. **DEPLOY.PROV could not see it** — `ProvisioningCommandsTest` asserts
     * that this file contains no demo seeder and that seeding leaves `Tenant::count() === 0`, and a
     * super-admin has no tenant, so the assertion passed while the account was created.
     *
     * It is now confined to the environments where the database is disposable, using the same allow-list
     * the demo seeders refuse outside of ({@see RefusesOutsideDevelopment}). Deleting the account
     * outright was considered and not taken: nothing in the codebase references `test@example.com`, but
     * keeping local and testing byte-identical to what every contributor and the QA audit already run is
     * the smaller change, and the exposure this finding is about is production.
     */
    public function run(): void
    {
        $this->call(PermissionCatalogSeeder::class);
        $this->call(PlanCatalogSeeder::class);

        if (! $this->isDisposableEnvironment()) {
            return;
        }

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
