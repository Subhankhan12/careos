<?php

/*
|--------------------------------------------------------------------------
| Agent eval harness (P0P.G4)
|--------------------------------------------------------------------------
|
| Shared, deterministic fixtures for the agent eval suite. This file is NOT a
| test file (it does not end in `Test.php`, so PHPUnit's default suffix never
| collects it); each eval file pulls it in with `require_once`.
|
| The eval suite LOCKS the safety properties of every agent — the electric
| fence, the autonomy caps, the grounding, and the "never trust the agent's
| numbers" rules — as regression tests. It mocks the LLM with deterministic
| inputs and makes NO real API calls (`evNoNetwork()`), asserting BEHAVIOR, not
| model quality. It must never change agent behavior; if writing an eval shows
| the behavior is actually wrong, stop and report instead of editing the eval
| to pass.
|
*/

use Illuminate\Support\Facades\Http;
use Modules\Audit\Services\AuditService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

if (! function_exists('evCtx')) {
    function evCtx(): TenantContext
    {
        return app(TenantContext::class);
    }

    /**
     * Guarantee no eval reaches a real LLM/HTTP endpoint. Any request is faked
     * (deterministic) and stray un-stubbed requests throw, so a regression that
     * introduced a live call would fail loudly.
     */
    function evNoNetwork(): void
    {
        Http::preventStrayRequests();
        Http::fake();
    }

    function evTenant(string $slug): Tenant
    {
        $tenant = Tenant::query()->create([
            'name' => ucfirst($slug).' Clinic',
            'slug' => $slug,
            'region' => 'eu',
            'status' => 'active',
        ]);

        evCtx()->set($tenant);

        return $tenant;
    }

    /**
     * A tenant staff user carrying exactly one starter role. The role's
     * permission catalogue is what gates each agent; the eval never grants extra.
     */
    function evUser(Tenant $tenant, string $roleKey): User
    {
        evCtx()->set($tenant);

        $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
        RoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id,
        ]);

        return $user;
    }

    function evPatient(array $overrides = []): Patient
    {
        return app(PatientService::class)->create([
            'first_name' => 'Eval',
            'last_name' => 'Patient',
            'date_of_birth' => '1988-06-06',
            'sex' => 'female',
            ...$overrides,
        ]);
    }

    function evChainOk(Tenant $tenant): bool
    {
        return app(AuditService::class)->verifyChain($tenant->id)['ok'];
    }
}
