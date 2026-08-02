<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Modules\Audit\Models\AuditEvent;
use Modules\Clinical\Models\Order;
use Modules\Lab\Models\LabOrder;
use Modules\Lab\Models\LabTest;
use Modules\Lab\Services\LabCatalogService;
use Modules\Lab\Services\LabOrderService;
use Modules\Lab\Services\LabResultService;
use Modules\Lab\Services\SpecimenService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * LAB.G5 — result routing + the "results to review" worklist: closing the order → result → review loop by
 * SURFACING the EXISTING resulted → reviewed step for lab orders (reuse OrderService's review, NOT reinvented).
 * Per docs/HOSPITAL-PHASE3-LAB-MAP.md. THE FENCE: the worklist shows resulted orders as FACTS + the recorded
 * STAT flag — NO computed priority/urgency ranking, NO computed critical-result flag; staff MAY sort by a
 * recorded fact (the flag or resulted-time). The result stays raw value + displayed range (G4's fence carried).
 */

function revCtx(): TenantContext
{
    return app(TenantContext::class);
}

function revUser(Tenant $tenant, string $role): User
{
    // twoFactorEnabled so the mandatory-MFA middleware lets HTTP requests reach the route/gate.
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** Place a lab order (as $orderer), collect + record a result (as $labTech) so the Order → resulted. */
function revResult(User $orderer, User $labTech, Patient $patient, LabTest $test, string $priority): LabOrder
{
    $labOrder = app(LabOrderService::class)->place($orderer, $patient, $test, $priority)['labOrder'];
    $specimen = app(SpecimenService::class)->collect($labTech, $labOrder);
    app(LabResultService::class)->record($labTech, $specimen, ['value' => '4.2']);

    return $labOrder;
}

/** @return array{tenant: Tenant, admin: User, doctor: User, doctor2: User, labTech: User, reception: User, patient: Patient, test: LabTest} */
function revFixture(string $slug = 'rev'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Lab', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    revCtx()->set($tenant);

    Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $admin = revUser($tenant, 'org_admin');  // lab.catalog — authors the catalog (setup)
    $doctor = revUser($tenant, 'doctor');    // order.manage — orders + reviews (the ordering clinician)
    $doctor2 = revUser($tenant, 'doctor');   // another ordering clinician (scoping proof)
    $labTech = revUser($tenant, 'lab_tech'); // order.manage + lab.result — results
    $reception = revUser($tenant, 'reception'); // no order.manage
    $patient = app(PatientService::class)->create(['first_name' => 'Erin', 'last_name' => 'Doe', 'date_of_birth' => '1990-04-04', 'sex' => 'female']);
    $test = app(LabCatalogService::class)->authorTest($admin, 'LAB-K', 'Potassium', 'Blood', 'mmol/L', '3.5–5.1');

    return compact('tenant', 'admin', 'doctor', 'doctor2', 'labTech', 'reception', 'patient', 'test');
}

test('a resulted lab order appears in the ORDERING clinician\'s review worklist (a fact) — not in another clinician\'s, not until resulted', function () {
    $fx = revFixture();
    $labOrder = revResult($fx['doctor'], $fx['labTech'], $fx['patient'], $fx['test'], LabOrder::PRIORITY_STAT);

    // A separate order placed + resulted by ANOTHER clinician (scoping — routed to the orderer).
    revResult($fx['doctor2'], $fx['labTech'], $fx['patient'], $fx['test'], LabOrder::PRIORITY_ROUTINE);

    // A merely-ORDERED (not resulted) order does NOT appear.
    app(LabOrderService::class)->place($fx['doctor'], $fx['patient'], $fx['test'], LabOrder::PRIORITY_ROUTINE);

    $wl = app(LabResultService::class)->reviewWorklist($fx['doctor']);
    expect($wl)->toHaveCount(1)
        ->and($wl->first()->id)->toBe($labOrder->id)
        ->and($wl->first()->order?->status)->toBe(Order::STATUS_RESULTED);

    // The other clinician sees only their own.
    expect(app(LabResultService::class)->reviewWorklist($fx['doctor2']))->toHaveCount(1);
});

test('reviewing a result REUSES the existing OrderService review flow (resulted → reviewed) and leaves the worklist', function () {
    $fx = revFixture();
    $labOrder = revResult($fx['doctor'], $fx['labTech'], $fx['patient'], $fx['test'], LabOrder::PRIORITY_ROUTINE);
    $order = Order::query()->findOrFail($labOrder->order_id);

    // The worklist links to the EXISTING clinical review endpoint (NOT a reinvented lab one).
    $props = $this->actingAs($fx['doctor'])->get('/lab/results/review')->viewData('page')['props'];
    expect($props['reviewUrl'])->toBe(route('clinical.orders.review'));

    // Reviewing through that reused endpoint advances the Order → reviewed (the existing gated transition).
    $this->actingAs($fx['doctor'])->post($props['reviewUrl'], ['order_id' => $order->id])->assertRedirect();
    expect($order->fresh()->status)->toBe(Order::STATUS_REVIEWED)
        ->and(AuditEvent::query()->where('action', 'order.reviewed')->where('patient_id', $fx['patient']->id)->count())->toBe(1);

    // Reviewed → no longer in the resulted-to-review worklist.
    expect(app(LabResultService::class)->reviewWorklist($fx['doctor']))->toHaveCount(0);
});

test('THE FENCE: the worklist carries FACTS + the recorded STAT flag but NO computed priority/urgency/critical/judgment field', function () {
    $fx = revFixture();
    revResult($fx['doctor'], $fx['labTech'], $fx['patient'], $fx['test'], LabOrder::PRIORITY_STAT);

    $props = $this->actingAs($fx['doctor'])->get('/lab/results/review')->viewData('page')['props'];

    // Facts present: the recorded STAT flag, the raw result, the displayed reference range, the resulted-time.
    $row = $props['orders'][0];
    expect($row['priority'])->toBe('stat')                        // the recorded flag (a fact)
        ->and($row['results'][0]['value'])->toBe('4.2')          // the raw value (a fact)
        ->and($row['reference']['reference_range'])->toBe('3.5–5.1') // the displayed range (a fact)
        ->and($row['resulted_at'])->not->toBeNull();

    // THE PROOF: no key anywhere is a computed judgment (priority ranking / critical / abnormal / review-first).
    $keys = [];
    $walk = function ($node) use (&$keys, &$walk): void {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                if (is_string($k)) {
                    $keys[] = strtolower($k);
                }
                $walk($v);
            }
        }
    };
    $walk($props);

    // 'priority' (the recorded flag) is allowed; a COMPUTED priority/ranking/critical/abnormal is not.
    foreach (['priority_score', 'computed_priority', 'urgency', 'rank', 'ranking', 'score', 'severity', 'acuity', 'critical', 'abnormal', 'flag', 'review_first', 'reviewfirst', 'deterioration', 'interpret', 'delta', 'grade'] as $forbidden) {
        foreach ($keys as $key) {
            expect(str_contains($key, $forbidden))->toBeFalse("The review worklist must not carry a computed-judgment key ({$forbidden}): saw '{$key}'");
        }
    }

    // The module computes/ranks nothing — no compute-priority / rank-by-urgency / flag-critical logic in Modules\Lab\src.
    $files = collect(File::allFiles(base_path('Modules/Lab/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');
    foreach (['computePriority', 'rankByUrgency', 'urgencyScore', 'priorityScore', 'flagCritical', 'isCritical', 'reviewFirst', 'whoFirst'] as $needle) {
        foreach ($files as $file) {
            expect(str_contains(File::get($file->getPathname()), $needle))->toBeFalse("Lab must not compute a priority/critical ranking ({$needle})");
        }
    }
});

test('THE FENCE: the worklist is ordered by resulted-time (a recorded fact), NOT by the recorded STAT flag', function () {
    $fx = revFixture();

    // A STAT order resulted EARLIER.
    $this->travelTo(Carbon::parse('2026-08-01 09:00:00'));
    revResult($fx['doctor'], $fx['labTech'], $fx['patient'], $fx['test'], LabOrder::PRIORITY_STAT);

    // A ROUTINE order resulted LATER.
    $this->travelTo(Carbon::parse('2026-08-01 11:00:00'));
    $later = revResult($fx['doctor'], $fx['labTech'], $fx['patient'], $fx['test'], LabOrder::PRIORITY_ROUTINE);

    $wl = app(LabResultService::class)->reviewWorklist($fx['doctor']);

    // The later-resulted ROUTINE order sorts FIRST — ordered by resulted-time, NOT by a computed STAT priority.
    expect($wl->first()->id)->toBe($later->id)
        ->and($wl->first()->priority)->toBe(LabOrder::PRIORITY_ROUTINE);
});

test('RBAC: the review worklist is order.manage-gated — reception is refused (service + HTTP)', function () {
    $fx = revFixture();

    expect(fn () => app(LabResultService::class)->reviewWorklist($fx['reception']))->toThrow(AuthorizationException::class);

    $this->actingAs($fx['reception'])->get('/lab/results/review')->assertForbidden();
});

test('the worklist is tenant-scoped: a resulted lab order in one tenant does not appear in another tenant\'s worklist', function () {
    $fxA = revFixture('alpha');
    revResult($fxA['doctor'], $fxA['labTech'], $fxA['patient'], $fxA['test'], LabOrder::PRIORITY_ROUTINE);

    $fxB = revFixture('beta');
    revCtx()->set($fxB['tenant']);

    // Tenant B's doctor has no resulted orders — A's are invisible (BelongsToTenant fail-closed).
    expect(app(LabResultService::class)->reviewWorklist($fxB['doctor']))->toHaveCount(0);
});
