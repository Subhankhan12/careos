<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Models\AuditEvent;
use Modules\Clinical\Models\Order;
use Modules\Lab\Exceptions\SpecimenException;
use Modules\Lab\Models\LabOrder;
use Modules\Lab\Models\Specimen;
use Modules\Lab\Models\SpecimenEvent;
use Modules\Lab\Services\LabCatalogService;
use Modules\Lab\Services\LabOrderService;
use Modules\Lab\Services\SpecimenService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * LAB.G3 — specimen tracking, the one genuine net-new lab entity: a specimen collected against a LAB.G2 order,
 * accessioned (unique per tenant), advanced through a legal-only state machine (collected → in_lab → resulted;
 * + rejected). Per docs/HOSPITAL-PHASE3-LAB-MAP.md §2.2. Operational record-keeping — no computed judgment.
 */

function specCtx(): TenantContext
{
    return app(TenantContext::class);
}

function specUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, branch: Branch, admin: User, phlebotomist: User, reception: User, patient: Patient, labOrder: LabOrder} */
function specFixture(string $slug = 'spec'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Lab', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    specCtx()->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $admin = specUser($tenant, 'org_admin');        // lab.catalog + order.manage + lab.result (setup)
    $phlebotomist = specUser($tenant, 'phlebotomist'); // lab.result, NO order.manage (can collect)
    $reception = specUser($tenant, 'reception');      // no lab.result
    $patient = app(PatientService::class)->create(['first_name' => 'Erin', 'last_name' => 'Doe', 'date_of_birth' => '1990-04-04', 'sex' => 'female']);
    $test = app(LabCatalogService::class)->authorTest($admin, 'LAB-K', 'Potassium', 'Blood', 'mmol/L', '3.5–5.1');
    $labOrder = app(LabOrderService::class)->place($admin, $patient, $test, LabOrder::PRIORITY_ROUTINE)['labOrder'];

    return compact('tenant', 'branch', 'admin', 'phlebotomist', 'reception', 'patient', 'labOrder');
}

test('a specimen is collected against a lab order — accessioned, typed, audited, read-logged; the Clinical Order is UNTOUCHED', function () {
    $fx = specFixture();

    // The phlebotomist (lab.result, NO order.manage) can collect — collection does not touch the Order.
    $specimen = app(SpecimenService::class)->collect($fx['phlebotomist'], $fx['labOrder'], 'EDTA tube', 'AC fossa');

    expect($specimen->status)->toBe(Specimen::STATUS_COLLECTED)
        ->and($specimen->accession_number)->toBe('ACC-000001')     // a generated factual identifier
        ->and($specimen->specimen_type)->toBe('Blood')             // from the LAB.G2 order overlay
        ->and($specimen->container_type)->toBe('EDTA tube')
        ->and($specimen->patient_id)->toBe($fx['patient']->id)
        // The collection is the first append-only state event.
        ->and(SpecimenEvent::query()->where('specimen_id', $specimen->id)->where('event_type', 'collected')->count())->toBe(1)
        // Audited (patient-scoped).
        ->and(AuditEvent::query()->where('action', 'specimen.accessioned')->where('patient_id', $fx['patient']->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'specimen.collected')->where('resource_type', 'specimen_event')->count())->toBe(1);

    // THE CLINICAL ORDER IS UNTOUCHED: collecting a specimen did NOT advance the reused Order's lifecycle
    // (it stays 'ordered'; the Order is Clinical's — the result step (G4) advances it).
    $order = Order::query()->findOrFail($fx['labOrder']->order_id);
    expect($order->status)->toBe(Order::STATUS_ORDERED);

    // Read-logged.
    $specimen->auditRead();
    expect(AuditEvent::query()->where('action', 'read')->where('resource_type', 'specimens')->where('patient_id', $fx['patient']->id)->count())->toBe(1);
});

test('the accession is UNIQUE per tenant (safe sequential generation)', function () {
    $fx = specFixture();
    $svc = app(SpecimenService::class);

    $a = $svc->collect($fx['admin'], $fx['labOrder']);
    $b = $svc->collect($fx['admin'], $fx['labOrder']); // a second specimen on the same order
    expect($a->accession_number)->toBe('ACC-000001')
        ->and($b->accession_number)->toBe('ACC-000002')
        ->and($a->accession_number)->not->toBe($b->accession_number);

    // Another tenant's accessions start fresh (unique PER TENANT).
    $fxB = specFixture('specb');
    expect(app(SpecimenService::class)->collect($fxB['admin'], $fxB['labOrder'])->accession_number)->toBe('ACC-000001');
});

test('the specimen state machine is LEGAL-ONLY (collected → in_lab → resulted); illegal throws; rejection needs a reason', function () {
    $fx = specFixture();
    $svc = app(SpecimenService::class);
    $specimen = $svc->collect($fx['admin'], $fx['labOrder']);

    // Cannot skip in_lab: collected → resulted is illegal.
    expect(fn () => $svc->transition($fx['admin'], $specimen, Specimen::STATUS_RESULTED))->toThrow(SpecimenException::class);

    // The legal path.
    $svc->transition($fx['admin'], $specimen, Specimen::STATUS_IN_LAB);
    $svc->transition($fx['admin'], $specimen->fresh(), Specimen::STATUS_RESULTED);
    expect($specimen->fresh()->status)->toBe(Specimen::STATUS_RESULTED)
        // resulted is terminal.
        ->and(fn () => $svc->transition($fx['admin'], $specimen->fresh(), Specimen::STATUS_IN_LAB))->toThrow(SpecimenException::class);

    // Rejection requires a reason.
    $s2 = $svc->collect($fx['admin'], $fx['labOrder']);
    expect(fn () => $svc->transition($fx['admin'], $s2, Specimen::STATUS_REJECTED))->toThrow(SpecimenException::class);
    $svc->transition($fx['admin'], $s2->fresh(), Specimen::STATUS_REJECTED, 'Haemolysed');
    expect($s2->fresh()->status)->toBe(Specimen::STATUS_REJECTED);
});

test('the specimen state history is APPEND-ONLY (model guard + DB trigger)', function () {
    $fx = specFixture();
    $svc = app(SpecimenService::class);
    $svc->collect($fx['admin'], $fx['labOrder']);
    $event = SpecimenEvent::query()->firstOrFail();

    // Model guard (belt).
    expect(fn () => $event->update(['reason' => 'tampered']))->toThrow(SpecimenException::class);

    // DB trigger (suspenders).
    expect(fn () => DB::table('specimen_events')->where('id', $event->id)->update(['reason' => 'tampered']))->toThrow(QueryException::class);
    expect(fn () => DB::table('specimen_events')->where('id', $event->id)->delete())->toThrow(QueryException::class);
});

test('FENCE: specimen state + accession are operational facts; no computed priority/urgency/routing judgment', function () {
    $fx = specFixture();
    app(SpecimenService::class)->collect($fx['admin'], $fx['labOrder']);

    // No computed-judgment column on specimens — state + accession are facts, not a computed priority/rank.
    $forbidden = ['priority_score', 'computed_priority', 'urgency_score', 'urgency', 'rank', 'routing', 'severity', 'acuity'];
    $columns = Schema::getColumnListing('specimens');
    foreach ($forbidden as $word) {
        expect($columns)->not->toContain($word, "specimens must not carry a computed-judgment column: {$word}");
    }

    // The module computes/routes nothing — no compute-priority / auto-route logic anywhere in Modules\Lab\src.
    $files = collect(File::allFiles(base_path('Modules/Lab/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');
    foreach (['computePriority', 'autoRoute', 'rankByUrgency', 'urgencyScore', 'routeByAcuity'] as $needle) {
        foreach ($files as $file) {
            expect(str_contains(File::get($file->getPathname()), $needle))->toBeFalse("Lab must not compute a priority/route ({$needle})");
        }
    }
});

test('RBAC: collecting + transitioning are lab.result-gated; reception is refused', function () {
    $fx = specFixture();
    $svc = app(SpecimenService::class);

    // reception holds no lab.result — cannot collect.
    expect(fn () => $svc->collect($fx['reception'], $fx['labOrder']))->toThrow(AuthorizationException::class);

    // The phlebotomist (lab.result) can collect + transition.
    $specimen = $svc->collect($fx['phlebotomist'], $fx['labOrder']);
    expect($specimen->status)->toBe(Specimen::STATUS_COLLECTED);
    expect(fn () => $svc->transition($fx['reception'], $specimen, Specimen::STATUS_IN_LAB))->toThrow(AuthorizationException::class);
    expect($svc->transition($fx['phlebotomist'], $specimen, Specimen::STATUS_IN_LAB)->status)->toBe(Specimen::STATUS_IN_LAB);
});

test('cross-tenant is fail-closed: a tenant cannot collect a specimen for another tenant lab order', function () {
    $fxA = specFixture('alpha');

    $fxB = specFixture('beta');
    specCtx()->set($fxB['tenant']);

    expect(fn () => app(SpecimenService::class)->collect($fxB['admin'], $fxA['labOrder']))
        ->toThrow(CrossTenantReferenceException::class);
});
