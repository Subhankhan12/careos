<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Modules\Audit\Models\AuditEvent;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Surgery\Exceptions\SurgicalInventoryException;
use Modules\Surgery\Models\CaseItemUsage;
use Modules\Surgery\Models\SurgicalItem;
use Modules\Surgery\Models\SurgicalItemStock;
use Modules\Surgery\Models\SurgicalStockMovement;
use Modules\Surgery\Services\SurgicalCaseService;
use Modules\Surgery\Services\SurgicalStockService;
use Modules\Surgery\Services\SurgicalUsageService;

uses(RefreshDatabase::class);

/*
 * SURGERY.G4 — consumables + implant tracking: REUSES (mirrors) the pharmacy G4 inventory + concurrency-safe
 * stock-decrement recipe; ADDS a net-new implant lot/serial/UDI traceability extension (recall support).
 * Operational domain, record-not-judge — no device-safety verdict. Per docs/HOSPITAL-PHASE5-SURGERY-MAP.md.
 */

function siCtx(): TenantContext
{
    return app(TenantContext::class);
}

function siUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, surgeon: User, scrubNurse: User, reception: User, patient: Patient, case: SurgicalCase, gauze: SurgicalItem, screw: SurgicalItem} */
function siFixture(string $slug = 'surginv'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Surgery', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    siCtx()->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $surgeon = siUser($tenant, 'surgeon');       // note.write + surgery.manage
    $scrubNurse = siUser($tenant, 'scrub_nurse'); // note.write, NO surgery.manage
    $reception = siUser($tenant, 'reception');   // neither
    $surgeonProfile = StaffProfile::query()->create([
        'first_name' => 'Sara', 'last_name' => 'Sharp', 'display_name' => 'Dr Sara Sharp',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id, 'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    $patient = app(PatientService::class)->create(['first_name' => 'Percy', 'last_name' => 'Patient', 'date_of_birth' => '1972-02-02', 'sex' => 'male']);
    $case = app(SurgicalCaseService::class)->schedule($surgeon, $patient, $surgeonProfile, 'Total hip replacement', Carbon::parse('2026-08-30 08:00:00'));

    $stockSvc = app(SurgicalStockService::class);
    $gauze = $stockSvc->createItem($surgeon, 'SUT-GAUZE', 'Gauze swab', false);
    $screw = $stockSvc->createItem($surgeon, 'IMP-SCREW', 'Titanium bone screw', true); // an implant
    $stockSvc->receive($surgeon, $gauze, 100);
    $stockSvc->receive($surgeon, $screw, 10);

    return compact('tenant', 'surgeon', 'scrubNurse', 'reception', 'patient', 'case', 'gauze', 'screw');
}

test('a surgical item is authored + stock received, tenant-scoped and audited', function () {
    $fx = siFixture();
    $stock = SurgicalItemStock::query()->where('surgical_item_id', $fx['gauze']->id)->firstOrFail();

    expect($fx['gauze']->tenant_id)->toBe($fx['tenant']->id)
        ->and($fx['gauze']->is_implant)->toBeFalse()
        ->and($fx['screw']->is_implant)->toBeTrue()
        ->and($stock->on_hand)->toBe(100)
        ->and(AuditEvent::query()->where('action', 'surgical_item.created')->where('resource_id', $fx['gauze']->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'surgical_stock.received')->count())->toBeGreaterThanOrEqual(1);
});

test('recording a consumable use DECREMENTS stock ATOMICALLY, tied to the case, audited and read-logged', function () {
    $fx = siFixture();

    $usage = app(SurgicalUsageService::class)->recordUsage($fx['scrubNurse'], $fx['case'], $fx['gauze'], 5);

    expect($usage->quantity)->toBe(5)
        ->and($usage->used_by)->toBe($fx['scrubNurse']->id)
        ->and($usage->surgical_case_id)->toBe($fx['case']->id)
        ->and($usage->patient_id)->toBe($fx['patient']->id)
        ->and(SurgicalItemStock::query()->where('surgical_item_id', $fx['gauze']->id)->firstOrFail()->on_hand)->toBe(95) // 100 - 5
        // A 'used' movement records the decrement, linked to the usage.
        ->and(SurgicalStockMovement::query()->where('case_item_usage_id', $usage->id)->where('type', 'used')->where('quantity_change', -5)->where('resulting_on_hand', 95)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'surgical_item.used')->where('resource_id', $usage->id)->where('patient_id', $fx['patient']->id)->count())->toBe(1);

    $usage->auditRead();
    expect(AuditEvent::query()->where('resource_id', $usage->id)->where('action', 'read')->count())->toBeGreaterThanOrEqual(1);
});

test('the stock guard: cannot use more than on-hand; on-hand never goes negative', function () {
    $fx = siFixture();
    $stockSvc = app(SurgicalStockService::class);
    // Reduce gauze to exactly 3 on-hand.
    $stock = SurgicalItemStock::query()->where('surgical_item_id', $fx['gauze']->id)->firstOrFail();
    $stockSvc->adjust($fx['surgeon'], $stock, 3, 'stock take');

    expect(fn () => app(SurgicalUsageService::class)->recordUsage($fx['scrubNurse'], $fx['case'], $fx['gauze'], 5))
        ->toThrow(SurgicalInventoryException::class);

    // No oversell, no negative — on-hand is untouched, and no usage/movement was written.
    expect(SurgicalItemStock::query()->where('surgical_item_id', $fx['gauze']->id)->firstOrFail()->on_hand)->toBe(3)
        ->and(CaseItemUsage::query()->where('surgical_item_id', $fx['gauze']->id)->count())->toBe(0);
});

test('an implant placement records lot/serial/UDI, decrements stock, is traceable to the patient, and is append-only', function () {
    $fx = siFixture();
    $svc = app(SurgicalUsageService::class);

    $placement = $svc->placeImplant($fx['surgeon'], $fx['case'], $fx['screw'], 'LOT-123', 'SN-9', 'UDI-XYZ', 'left hip');

    expect($placement->lot_number)->toBe('LOT-123')
        ->and($placement->serial_number)->toBe('SN-9')
        ->and($placement->udi)->toBe('UDI-XYZ')
        ->and($placement->patient_id)->toBe($fx['patient']->id)             // TRACEABLE to the patient
        ->and($placement->surgical_case_id)->toBe($fx['case']->id)
        ->and($placement->case_item_usage_id)->not->toBeNull()               // linked to the stock decrement
        ->and(SurgicalItemStock::query()->where('surgical_item_id', $fx['screw']->id)->firstOrFail()->on_hand)->toBe(9) // 10 - 1
        ->and(AuditEvent::query()->where('action', 'implant.placed')->where('resource_id', $placement->id)->count())->toBe(1);

    // A consumable cannot be placed as an implant; an implant needs a lot.
    expect(fn () => $svc->placeImplant($fx['surgeon'], $fx['case'], $fx['gauze'], 'LOT-1', null, null, null))->toThrow(SurgicalInventoryException::class);
    expect(fn () => $svc->placeImplant($fx['surgeon'], $fx['case'], $fx['screw'], '  ', null, null, null))->toThrow(SurgicalInventoryException::class);

    // Append-only: the placement record is preserved (model guard + DB trigger).
    expect(fn () => $placement->update(['lot_number' => 'tampered']))->toThrow(SurgicalInventoryException::class);
    expect(fn () => DB::table('implant_placements')->where('id', $placement->id)->delete())->toThrow(QueryException::class);
});

test('THE RECALL LOOKUP: a lot / UDI resolves to the patients it was placed in (a factual query)', function () {
    $fx = siFixture();
    $svc = app(SurgicalUsageService::class);

    // The same lot placed in TWO patients (a second case in the same tenant).
    $svc->placeImplant($fx['surgeon'], $fx['case'], $fx['screw'], 'RECALL-LOT', null, 'UDI-R', null);
    $patientB = app(PatientService::class)->create(['first_name' => 'Bea', 'last_name' => 'Booth', 'date_of_birth' => '1980-05-05', 'sex' => 'female']);
    $surgeonProfile = StaffProfile::query()->firstOrFail();
    $caseB = app(SurgicalCaseService::class)->schedule($fx['surgeon'], $patientB, $surgeonProfile, 'Knee replacement', Carbon::parse('2026-08-31 08:00:00'));
    $svc->placeImplant($fx['surgeon'], $caseB, $fx['screw'], 'RECALL-LOT', null, 'UDI-R', null);

    // The recall lookup returns BOTH placements (both patients) — a factual traceability query, not a verdict.
    expect($svc->patientsForLot('RECALL-LOT')->pluck('patient_id')->unique())->toHaveCount(2)
        ->and($svc->patientsForLot('UDI-R')->count())->toBe(2)                 // by UDI too
        ->and($svc->patientsForLot('nonexistent-lot')->count())->toBe(0);

    // Given a patient, their implant history.
    expect($svc->implantsForPatient($fx['patient'])->count())->toBeGreaterThanOrEqual(1);
});

test('FENCE: stock/usage/implant traceability are operational facts — no device-safety verdict / judgment field', function () {
    // Traceability is RECORD-KEEPING. What must NEVER exist is a COMPUTED device-safety verdict.
    $forbidden = ['verdict', 'safe', 'recall_status', 'pass_fail', 'compliant', 'compliance', 'grade', 'severity', 'risk', 'score', 'rating', 'approved'];
    foreach (['surgical_items', 'surgical_item_stocks', 'surgical_stock_movements', 'case_item_usages', 'implant_placements'] as $table) {
        $columns = Schema::getColumnListing($table);
        foreach ($forbidden as $word) {
            expect($columns)->not->toContain($word, "{$table} must not carry a device-safety judgment column: {$word}");
        }
    }

    // Below-stock is a FACTUAL count, not a graded alert; and no device-verification method exists in the module.
    $fx = siFixture();
    $stock = SurgicalItemStock::query()->where('surgical_item_id', $fx['gauze']->id)->firstOrFail();
    expect($stock->isBelowThreshold())->toBeBool();

    $files = collect(File::allFiles(base_path('Modules/Surgery/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');
    foreach (['verifyDevice', 'recallStatus', 'deviceSafe', 'gradeImplant', 'isRecalled'] as $needle) {
        foreach ($files as $file) {
            expect(str_contains(File::get($file->getPathname()), $needle))->toBeFalse("Surgery must not judge/verify the device ({$needle})");
        }
    }
});

test('RBAC: usage is note.write (the team); stock admin is surgery.manage; reception is refused', function () {
    $fx = siFixture();
    $usageSvc = app(SurgicalUsageService::class);
    $stockSvc = app(SurgicalStockService::class);

    // reception holds neither note.write nor surgery.manage — refused everywhere.
    expect(fn () => $usageSvc->recordUsage($fx['reception'], $fx['case'], $fx['gauze'], 1))->toThrow(AuthorizationException::class);
    expect(fn () => $stockSvc->createItem($fx['reception'], 'X', 'X'))->toThrow(AuthorizationException::class);

    // the scrub nurse (note.write) records usage, but CANNOT manage stock (needs surgery.manage).
    expect($usageSvc->recordUsage($fx['scrubNurse'], $fx['case'], $fx['gauze'], 1)->id)->not->toBeNull();
    expect(fn () => $stockSvc->createItem($fx['scrubNurse'], 'Y', 'Y'))->toThrow(AuthorizationException::class);
});

test('cross-tenant is fail-closed: a tenant cannot use an item against another tenant case', function () {
    $fxA = siFixture('alpha');
    $caseA = $fxA['case'];
    $gauzeA = $fxA['gauze'];

    $fxB = siFixture('beta');
    siCtx()->set($fxB['tenant']);

    expect(fn () => app(SurgicalUsageService::class)->recordUsage($fxB['surgeon'], $caseA, $gauzeA, 1))
        ->toThrow(CrossTenantReferenceException::class);
});
