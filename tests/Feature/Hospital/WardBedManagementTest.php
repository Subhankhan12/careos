<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Models\AuditEvent;
use Modules\Audit\Services\AuditService;
use Modules\Hospital\Exceptions\BedNotAvailableException;
use Modules\Hospital\Exceptions\BedStatusTransitionException;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\Ward;
use Modules\Hospital\Services\BedService;
use Modules\Hospital\Services\WardService;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * HOSPITAL.G1 — the inpatient foundation: a NET-NEW Bed/Ward model (not a Scheduling
 * Resource — occupancy is continuous, not a timed slot), a concurrency-safe bed-claim
 * primitive (the BookingService lock idiom applied to Bed), and additive inpatient RBAC.
 * These tests assert BEHAVIOUR (DB state, audit rows, gate refusals) — never markup.
 */

function hospTenant(string $slug = 'hosp'): Tenant
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Hospital', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    return $tenant;
}

function hospUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

test('a bed manager creates a ward and a bed — tenant + branch scoped, and audited', function () {
    $tenant = hospTenant();
    $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
    $manager = hospUser($tenant, 'bed_manager');

    $ward = app(WardService::class)->create($manager, $branch->id, 'Ward 3A', '3A');
    expect($ward->tenant_id)->toBe($tenant->id)
        ->and($ward->branch_id)->toBe($branch->id)
        ->and($ward->active)->toBeTrue();

    $bed = app(BedService::class)->create($manager, $ward, '12', Bed::TYPE_GENERAL);
    expect($bed->tenant_id)->toBe($tenant->id)
        ->and($bed->branch_id)->toBe($branch->id)
        ->and($bed->ward_id)->toBe($ward->id)
        ->and($bed->bed_type)->toBe(Bed::TYPE_GENERAL)
        ->and($bed->status)->toBe(Bed::STATUS_FREE) // beds start free (housekeeping), never with a patient
        ->and($bed->active)->toBeTrue();

    // Creation is audited via the app-layer model hooks, and the chain stays valid.
    expect(AuditEvent::where('action', 'ward.created')->where('resource_id', $ward->id)->count())->toBe(1)
        ->and(AuditEvent::where('action', 'bed.created')->where('resource_id', $bed->id)->count())->toBe(1)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('bed housekeeping status follows legal transitions only, each audited via the domain event', function () {
    $tenant = hospTenant();
    $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
    $manager = hospUser($tenant, 'bed_manager');
    $ward = app(WardService::class)->create($manager, $branch->id, 'Ward', 'W1');
    $beds = app(BedService::class);
    $bed = $beds->create($manager, $ward, '1', Bed::TYPE_GENERAL);

    // free -> blocked -> free (legal housekeeping)
    $beds->setStatus($manager, $bed, Bed::STATUS_BLOCKED, 'deep clean');
    expect($bed->fresh()->status)->toBe(Bed::STATUS_BLOCKED);
    $beds->setStatus($manager, $bed->fresh(), Bed::STATUS_FREE);
    expect($bed->fresh()->status)->toBe(Bed::STATUS_FREE);

    // free -> cleaning is ILLEGAL (a free bed does not need turnover)
    expect(fn () => $beds->setStatus($manager, $bed->fresh(), Bed::STATUS_CLEANING))
        ->toThrow(BedStatusTransitionException::class);

    // free -> occupied via setStatus is refused — occupancy must go through claim()
    expect(fn () => $beds->setStatus($manager, $bed->fresh(), Bed::STATUS_OCCUPIED))
        ->toThrow(BedStatusTransitionException::class);

    // exactly the two legal transitions were audited; illegal attempts wrote nothing
    expect(AuditEvent::where('action', 'bed.status_changed')->where('resource_id', $bed->id)->count())->toBe(2)
        ->and($bed->fresh()->status)->toBe(Bed::STATUS_FREE);
});

test('a bed turns over occupied -> cleaning -> free, and cannot skip cleaning', function () {
    $tenant = hospTenant();
    $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
    $manager = hospUser($tenant, 'bed_manager');
    $clerk = hospUser($tenant, 'admissions_clerk'); // holds admission.manage
    $ward = app(WardService::class)->create($manager, $branch->id, 'Ward', 'W1');
    $beds = app(BedService::class);
    $bed = $beds->create($manager, $ward, '1', Bed::TYPE_GENERAL);

    $beds->claim($clerk, $bed); // free -> occupied
    expect($bed->fresh()->status)->toBe(Bed::STATUS_OCCUPIED);

    // occupied -> free directly is illegal (must be cleaned first)
    expect(fn () => $beds->setStatus($manager, $bed->fresh(), Bed::STATUS_FREE))
        ->toThrow(BedStatusTransitionException::class);

    $beds->setStatus($manager, $bed->fresh(), Bed::STATUS_CLEANING); // occupied -> cleaning
    $beds->setStatus($manager, $bed->fresh(), Bed::STATUS_FREE);     // cleaning -> free
    expect($bed->fresh()->status)->toBe(Bed::STATUS_FREE);
});

test('claim occupies a free bed and a second claim on it conflicts', function () {
    $tenant = hospTenant();
    $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
    $manager = hospUser($tenant, 'bed_manager');
    $clerk = hospUser($tenant, 'admissions_clerk');
    $ward = app(WardService::class)->create($manager, $branch->id, 'Ward', 'W1');
    $beds = app(BedService::class);
    $bed = $beds->create($manager, $ward, '1', Bed::TYPE_GENERAL);

    $claimed = $beds->claim($clerk, $bed);
    expect($claimed->status)->toBe(Bed::STATUS_OCCUPIED);

    expect(fn () => $beds->claim($clerk, $bed->fresh()))->toThrow(BedNotAvailableException::class);
});

test('bed/ward management + claim are RBAC-gated (server Gate authoritative)', function () {
    $tenant = hospTenant();
    $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
    $manager = hospUser($tenant, 'bed_manager');       // ward.manage + bed.manage, NOT admission.manage
    $wardNurse = hospUser($tenant, 'ward_nurse');      // clinical only — no bed/ward/admission perms
    $ward = app(WardService::class)->create($manager, $branch->id, 'Ward', 'W1');
    $bed = app(BedService::class)->create($manager, $ward, '1', Bed::TYPE_GENERAL);

    // a ward nurse cannot create/manage wards or beds
    expect(fn () => app(WardService::class)->create($wardNurse, $branch->id, 'X', 'X'))->toThrow(AuthorizationException::class);
    expect(fn () => app(BedService::class)->create($wardNurse, $ward, '2', Bed::TYPE_GENERAL))->toThrow(AuthorizationException::class);
    expect(fn () => app(BedService::class)->setStatus($wardNurse, $bed, Bed::STATUS_BLOCKED))->toThrow(AuthorizationException::class);

    // claiming a bed needs admission.manage — the bed manager (bed.manage only) is refused
    expect(fn () => app(BedService::class)->claim($manager, $bed))->toThrow(AuthorizationException::class);
    // ...and so is the ward nurse
    expect(fn () => app(BedService::class)->claim($wardNurse, $bed))->toThrow(AuthorizationException::class);
});

test('wards and beds are tenant isolated and reject cross-tenant references (fail-closed)', function () {
    $tenantA = hospTenant('alpha');
    $branchA = Branch::create(['name' => 'A Main', 'code' => 'MAIN']);
    $managerA = hospUser($tenantA, 'bed_manager');
    $wardA = app(WardService::class)->create($managerA, $branchA->id, 'Ward A', 'WA');
    $bedA = app(BedService::class)->create($managerA, $wardA, '1', Bed::TYPE_GENERAL);

    // switch to tenant B — tenant A's ward/bed are invisible
    $tenantB = hospTenant('beta');
    app(TenantContext::class)->set($tenantB);
    expect(Ward::find($wardA->id))->toBeNull()
        ->and(Bed::find($bedA->id))->toBeNull();

    // creating a ward in B that points at A's branch is rejected as a cross-tenant link
    $managerB = hospUser($tenantB, 'bed_manager');
    expect(fn () => app(WardService::class)->create($managerB, $branchA->id, 'X', 'X'))
        ->toThrow(CrossTenantReferenceException::class);
});

test('FENCE: a bed/ward carries no clinical-judgment column — status is housekeeping only', function () {
    hospTenant();

    $bedCols = Schema::getColumnListing('beds');
    $wardCols = Schema::getColumnListing('wards');

    // No acuity / severity / score / risk / grade / triage / early-warning / patient link:
    // a bed records operational housekeeping facts, never a clinical judgment about a patient.
    $forbidden = ['acuity', 'severity', 'score', 'risk', 'grade', 'flag', 'priority', 'abnormal',
        'triage', 'deterioration', 'ews', 'news', 'patient_id'];
    foreach ($forbidden as $word) {
        expect($bedCols)->not->toContain($word, "beds must not carry a clinical-judgment column: {$word}")
            ->and($wardCols)->not->toContain($word, "wards must not carry a clinical-judgment column: {$word}");
    }

    // status IS present and is a closed set of housekeeping states (no judgment).
    expect($bedCols)->toContain('status')
        ->and(Bed::STATUSES)->toBe(['free', 'occupied', 'cleaning', 'blocked']);
});
