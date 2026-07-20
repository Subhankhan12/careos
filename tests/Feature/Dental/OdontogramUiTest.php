<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Audit\Models\AuditEvent;
use Modules\Dental\Models\ToothRecord;
use Modules\Dental\Services\ToothChartService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * DENTAL.G2 — the odontogram chart UI. Presentational over the G1 ToothChartService
 * (P0D.GU): these tests prove the page renders a patient's CURRENT chart + history from
 * real charted data, recording goes through the append-only service (a correction
 * preserves prior state — proven end-to-end via the UI action), RBAC (dental.chart to
 * record / patient.view to read), tenant scoping, and the fence in the UI (the rendered
 * payload carries charted conditions ONLY — no severity/score/grade/risk/flag).
 */

function ogCtx(): TenantContext
{
    return app(TenantContext::class);
}

function ogUser(Tenant $tenant, string $role): User
{
    ogCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * @return array{tenant: Tenant, doctor: User, patient: Patient}
 */
function ogFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Dental', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    ogCtx()->set($tenant);
    $doctor = ogUser($tenant, 'doctor'); // holds dental.chart + patient.view (the general dentist)
    $patient = app(PatientService::class)->create(['first_name' => 'Tom', 'last_name' => 'Tooth', 'date_of_birth' => '1990-03-03', 'sex' => 'male']);

    return compact('tenant', 'doctor', 'patient');
}

/**
 * Recursively assert no interpretation/judgment key leaked into the rendered payload.
 *
 * @param  array<mixed>  $data
 */
function ogAssertNoJudgment(array $data): void
{
    $forbidden = ['severity', 'score', 'grade', 'risk', 'flag', 'abnormal', 'priority', 'recommendation', 'rating', 'interpretation', 'diagnosis', 'verdict', 'alert', 'normal'];
    foreach ($data as $key => $value) {
        expect(in_array((string) $key, $forbidden, true))->toBeFalse("interpretation key '{$key}' leaked into the odontogram payload");
        if (is_array($value)) {
            ogAssertNoJudgment($value);
        }
    }
}

test('the odontogram renders the patient current chart + history, and the payload carries no interpretation field', function () {
    $fx = ogFixture();
    app(ToothChartService::class)->chart($fx['doctor'], $fx['patient'], '11', 'occlusal', 'caries');
    app(ToothChartService::class)->chart($fx['doctor'], $fx['patient'], '21', null, 'crown');

    ogCtx()->forget();
    $this->actingAs($fx['doctor'])
        ->get(route('dental.chart', $fx['patient']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dental/Odontogram')
            ->where('patient.id', $fx['patient']->id)
            ->has('chart', 2)
            ->has('history', 2)
            ->has('teeth.permanent', 32)
            ->has('teeth.primary', 20)
            ->has('conditions.surface')
            ->where('actions.can_chart', true)
            // The rendered chart is charted facts only — no judgment key anywhere (electric fence).
            ->where('chart', function ($chart) {
                ogAssertNoJudgment(collect($chart)->toArray());

                return true;
            })
            ->where('history', function ($history) {
                ogAssertNoJudgment(collect($history)->toArray());

                return true;
            }));
});

test('recording through the UI goes through the append-only service — a correction preserves prior history', function () {
    $fx = ogFixture();

    // First charting via the UI action.
    ogCtx()->forget();
    $this->actingAs($fx['doctor'])
        ->post(route('dental.chart.store', $fx['patient']->id), ['tooth' => '11', 'surface' => 'occlusal', 'condition' => 'caries'])
        ->assertRedirect(route('dental.chart', $fx['patient']->id));
    expect(ToothRecord::query()->where('patient_id', $fx['patient']->id)->count())->toBe(1);

    // A correction via the UI: a NEW record (append-only), the prior 'caries' preserved.
    ogCtx()->forget();
    $this->actingAs($fx['doctor'])
        ->post(route('dental.chart.store', $fx['patient']->id), ['tooth' => '11', 'surface' => 'occlusal', 'condition' => 'restoration', 'reason' => 'filling placed'])
        ->assertRedirect();
    expect(ToothRecord::query()->where('patient_id', $fx['patient']->id)->count())->toBe(2)
        ->and(ToothRecord::query()->where('patient_id', $fx['patient']->id)->where('charted_condition', 'caries')->exists())->toBeTrue();

    // The current chart shows the latest (restoration); the history retains both.
    ogCtx()->forget();
    $this->actingAs($fx['doctor'])
        ->get(route('dental.chart', $fx['patient']->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('chart', fn ($chart) => collect($chart)->firstWhere('surface', 'occlusal')['condition'] === 'restoration')
            ->has('history', 2));

    // Both charting actions were audited through the service.
    expect(AuditEvent::query()->where('tenant_id', $fx['tenant']->id)->where('action', 'dental.tooth_charted')->count())->toBe(2);
});

test('RBAC: dental.chart records, patient.view reads, and neither is bypassable', function () {
    $fx = ogFixture();

    // reception has patient.view (can VIEW) but not dental.chart (cannot RECORD).
    $reception = ogUser($fx['tenant'], 'reception');
    ogCtx()->forget();
    $this->actingAs($reception)->get(route('dental.chart', $fx['patient']->id))->assertOk();
    ogCtx()->forget();
    $this->actingAs($reception)->post(route('dental.chart.store', $fx['patient']->id), ['tooth' => '11', 'condition' => 'present'])->assertForbidden();
    expect(ToothRecord::query()->count())->toBe(0);

    // billing has neither patient.view nor dental.chart → cannot even view.
    $billing = ogUser($fx['tenant'], 'billing');
    ogCtx()->forget();
    $this->actingAs($billing)->get(route('dental.chart', $fx['patient']->id))->assertForbidden();
});

test('the odontogram is tenant-scoped: a cross-tenant patient fails closed as 404', function () {
    $alpha = ogFixture('alpha');
    $beta = ogFixture('beta');

    ogCtx()->forget();
    $this->actingAs($beta['doctor'])->get(route('dental.chart', $alpha['patient']->id))->assertNotFound();
    ogCtx()->forget();
    $this->actingAs($beta['doctor'])->post(route('dental.chart.store', $alpha['patient']->id), ['tooth' => '11', 'condition' => 'present'])->assertNotFound();

    // No alpha record was created by the beta attempts.
    ogCtx()->set($alpha['tenant']);
    expect(ToothRecord::query()->where('patient_id', $alpha['patient']->id)->count())->toBe(0);
});
