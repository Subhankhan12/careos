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
use Modules\Surgery\Exceptions\SurgicalChecklistException;
use Modules\Surgery\Models\SurgicalCase;
use Modules\Surgery\Models\SurgicalChecklist;
use Modules\Surgery\Models\SurgicalChecklistItem;
use Modules\Surgery\Models\SurgicalChecklistTemplateItem;
use Modules\Surgery\Services\SurgicalCaseService;
use Modules\Surgery\Services\SurgicalChecklistService;

uses(RefreshDatabase::class);

/*
 * SURGERY.G3 — the WHO Surgical Safety Checklist: RECORDED, NOT ENFORCED. The team completes a three-phase
 * checklist (sign-in / time-out / sign-out); CareOS records each confirmation (append-only) and computes NO
 * safety verdict. THE CRUX FENCE LINE: the checklist NEVER gates the case — a surgery proceeds regardless of
 * checklist completeness. Per docs/HOSPITAL-PHASE5-SURGERY-MAP.md §2.4.
 */

function sclCtx(): TenantContext
{
    return app(TenantContext::class);
}

function sclUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->forTenant($tenant)->create();
    RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('key', $role)->firstOrFail()->id]);

    return $user;
}

/** @return array{tenant: Tenant, surgeon: User, scrubNurse: User, reception: User, patient: Patient, case: SurgicalCase} */
function sclFixture(string $slug = 'surgchk'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug).' Surgery', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    sclCtx()->set($tenant);

    $branch = Branch::create(['name' => 'Main', 'code' => strtoupper(substr($slug, 0, 4)), 'timezone' => 'Europe/Zurich']);
    $surgeon = sclUser($tenant, 'surgeon');       // note.write + surgery.manage
    $scrubNurse = sclUser($tenant, 'scrub_nurse'); // note.write, NO surgery.manage
    $reception = sclUser($tenant, 'reception');   // NO note.write
    $surgeonProfile = StaffProfile::query()->create([
        'first_name' => 'Sara', 'last_name' => 'Sharp', 'display_name' => 'Dr Sara Sharp',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id, 'status' => StaffProfile::STATUS_ACTIVE,
    ]);
    $patient = app(PatientService::class)->create(['first_name' => 'Percy', 'last_name' => 'Patient', 'date_of_birth' => '1972-02-02', 'sex' => 'male']);
    $case = app(SurgicalCaseService::class)->schedule($surgeon, $patient, $surgeonProfile, 'Laparoscopic appendectomy', Carbon::parse('2026-08-25 08:00:00'));

    return compact('tenant', 'surgeon', 'scrubNurse', 'reception', 'patient', 'case');
}

test('the WHO checklist opens for a case and seeds the standard 3-phase TENANT-AUTHORED template (editable)', function () {
    $fx = sclFixture();

    $checklist = app(SurgicalChecklistService::class)->openChecklist($fx['surgeon'], $fx['case']);

    expect($checklist->surgical_case_id)->toBe($fx['case']->id)
        ->and($checklist->patient_id)->toBe($fx['patient']->id);

    // All three WHO phases have standard items, in the tenant's OWN editable table (not a licensed set).
    foreach (SurgicalChecklistTemplateItem::PHASES as $phase) {
        expect(SurgicalChecklistTemplateItem::query()->where('phase', $phase)->where('active', true)->count())->toBeGreaterThan(0);
    }

    // The template is tenant-authored + editable: a tenant can deactivate an item and it drops off the checklist.
    $item = SurgicalChecklistTemplateItem::query()->where('phase', 'sign_in')->firstOrFail();
    $item->update(['active' => false]);
    $phases = collect(app(SurgicalChecklistService::class)->forCase($fx['case'])['phases']);
    $signIn = $phases->firstWhere('phase', 'sign_in');
    expect(collect($signIn['items'])->pluck('template_item_id'))->not->toContain($item->id);
});

test('a team member confirms an item — checked / by-whom / when, patient-scoped, audited and read-logged', function () {
    $fx = sclFixture();
    $svc = app(SurgicalChecklistService::class);
    $svc->openChecklist($fx['surgeon'], $fx['case']);
    $item = SurgicalChecklistTemplateItem::query()->where('phase', 'time_out')->orderBy('display_order')->firstOrFail();

    $confirmation = $svc->confirmItem($fx['scrubNurse'], $fx['case'], $item->id, true, null);

    expect($confirmation->checked)->toBeTrue()
        ->and($confirmation->confirmed_by)->toBe($fx['scrubNurse']->id)  // WHO checked it
        ->and($confirmation->confirmed_at)->not->toBeNull()               // WHEN
        ->and($confirmation->phase)->toBe('time_out')                     // snapshot
        ->and($confirmation->label)->toBe($item->label)
        ->and($confirmation->patient_id)->toBe($fx['patient']->id)
        ->and(AuditEvent::query()->where('action', 'surgical_checklist.item_confirmed')->where('resource_id', $confirmation->id)->where('patient_id', $fx['patient']->id)->count())->toBe(1);

    // The factual read model reflects the confirmation as a plain count.
    $timeOut = collect($svc->forCase($fx['case'])['phases'])->firstWhere('phase', 'time_out');
    expect($timeOut['checked_count'])->toBe(1);

    // Read-logged (LogsReads) on the checklist container.
    $checklist = SurgicalChecklist::query()->firstOrFail();
    $checklist->auditRead();
    expect(AuditEvent::query()->where('resource_id', $checklist->id)->where('action', 'read')->count())->toBeGreaterThanOrEqual(1);
});

test('the completion log is APPEND-ONLY: a correction is a new row, history preserved (model guard + DB trigger)', function () {
    $fx = sclFixture();
    $svc = app(SurgicalChecklistService::class);
    $svc->openChecklist($fx['surgeon'], $fx['case']);
    $item = SurgicalChecklistTemplateItem::query()->firstOrFail();

    $first = $svc->confirmItem($fx['surgeon'], $fx['case'], $item->id, true, null);
    // A correction is a NEW confirmation (with a reason), never an edit.
    $svc->confirmItem($fx['surgeon'], $fx['case'], $item->id, false, 'recorded in error');

    // History preserved: two rows for the same item; current state = the latest (unchecked).
    expect(SurgicalChecklistItem::query()->where('template_item_id', $item->id)->count())->toBe(2);
    $phase = collect($svc->forCase($fx['case'])['phases'])->firstWhere('phase', $item->phase);
    expect(collect($phase['items'])->firstWhere('template_item_id', $item->id)['checked'])->toBeFalse();

    // Model guard (belt) + DB triggers (suspenders).
    expect(fn () => $first->update(['checked' => false]))->toThrow(SurgicalChecklistException::class);
    expect(fn () => DB::table('surgical_checklist_items')->where('id', $first->id)->update(['checked' => false]))->toThrow(QueryException::class);
    expect(fn () => DB::table('surgical_checklist_items')->where('id', $first->id)->delete())->toThrow(QueryException::class);
});

test('THE FENCE (the crux): the checklist does NOT gate the case — it transitions REGARDLESS of checklist completeness', function () {
    $fx = sclFixture();
    $cases = app(SurgicalCaseService::class);
    // Open the checklist but confirm NOTHING — it is entirely empty (0 of everything checked).
    app(SurgicalChecklistService::class)->openChecklist($fx['surgeon'], $fx['case']);
    expect(SurgicalChecklistItem::query()->count())->toBe(0);

    // The surgery proceeds through the FULL G2 lifecycle with an EMPTY checklist — incision (in_progress) is
    // NOT blocked. A blocking checklist would be a safety-enforcement device; CareOS records, never enforces.
    $case = $fx['case'];
    foreach ([SurgicalCase::STATUS_PRE_OP, SurgicalCase::STATUS_IN_PROGRESS, SurgicalCase::STATUS_COMPLETED, SurgicalCase::STATUS_POST_OP] as $to) {
        $cases->transition($fx['surgeon'], $case, $to);
        $case = $case->fresh();
    }
    expect($case->status)->toBe(SurgicalCase::STATUS_POST_OP);

    // Confirming (or not) items never changes which transitions are legal — the G2 machine is independent of
    // the checklist. A fresh case with a partial checklist still advances identically.
    $fx2 = sclFixture('surgchkb');
    $svc = app(SurgicalChecklistService::class);
    $svc->openChecklist($fx2['surgeon'], $fx2['case']);
    $svc->confirmItem($fx2['surgeon'], $fx2['case'], SurgicalChecklistTemplateItem::query()->firstOrFail()->id, true, null); // 1 of many
    expect($cases->transition($fx2['surgeon'], $fx2['case'], SurgicalCase::STATUS_PRE_OP)->status)->toBe(SurgicalCase::STATUS_PRE_OP);
});

test('THE FENCE: no computed safety verdict / pass-fail; the read model is a FACTUAL count, not a judgment', function () {
    // The checklist RECORDS. What must NEVER exist is a COMPUTED safety verdict column.
    $forbidden = ['verdict', 'passed', 'safe', 'pass_fail', 'compliant', 'compliance', 'score', 'grade', 'rating', 'safe_to_proceed'];
    foreach (['surgical_checklists', 'surgical_checklist_items', 'surgical_checklist_template_items'] as $table) {
        $columns = Schema::getColumnListing($table);
        foreach ($forbidden as $word) {
            expect($columns)->not->toContain($word, "{$table} must not carry a computed safety verdict column: {$word}");
        }
    }

    // The read model exposes a FACT (checked_count / total) — never a verdict / passed / safe key.
    $fx = sclFixture();
    $phase = collect(app(SurgicalChecklistService::class)->forCase($fx['case'])['phases'])->first();
    expect(array_keys($phase))->toContain('checked_count')->toContain('total')
        ->and(array_keys($phase))->not->toContain('verdict')->not->toContain('passed')->not->toContain('safe');

    // No "safe to proceed" / "checklist passed" judgment method exists anywhere in the module.
    $files = collect(File::allFiles(base_path('Modules/Surgery/src')))->filter(fn ($f): bool => $f->getExtension() === 'php');
    foreach (['safeToProceed', 'checklistPassed', 'isSafe', 'canProceed', 'requireChecklist', 'gateOnChecklist'] as $needle) {
        foreach ($files as $file) {
            expect(str_contains(File::get($file->getPathname()), $needle))->toBeFalse("The checklist must never gate/judge the surgery ({$needle})");
        }
    }
});

test('RBAC: the surgical team (note.write) completes the checklist; reception is refused; seeding is surgery.manage', function () {
    $fx = sclFixture();
    $svc = app(SurgicalChecklistService::class);
    $svc->openChecklist($fx['surgeon'], $fx['case']);
    $item = SurgicalChecklistTemplateItem::query()->firstOrFail();

    // reception holds no note.write — refused at the gate (fail closed).
    expect(fn () => $svc->openChecklist($fx['reception'], $fx['case']))->toThrow(AuthorizationException::class);
    expect(fn () => $svc->confirmItem($fx['reception'], $fx['case'], $item->id, true, null))->toThrow(AuthorizationException::class);

    // the scrub nurse (note.write) completes it; the surgeon reseeds the template (surgery.manage config act).
    expect($svc->confirmItem($fx['scrubNurse'], $fx['case'], $item->id, true, null)->id)->not->toBeNull()
        ->and($svc->seedTemplate($fx['surgeon']))->toBeInt();
    expect(fn () => $svc->seedTemplate($fx['reception']))->toThrow(AuthorizationException::class);
});

test('cross-tenant is fail-closed: a tenant cannot confirm another tenant checklist item', function () {
    $fxA = sclFixture('alpha');
    app(SurgicalChecklistService::class)->openChecklist($fxA['surgeon'], $fxA['case']);
    $caseA = $fxA['case'];
    $itemA = SurgicalChecklistTemplateItem::query()->firstOrFail();

    $fxB = sclFixture('beta');
    sclCtx()->set($fxB['tenant']);

    expect(fn () => app(SurgicalChecklistService::class)->confirmItem($fxB['surgeon'], $caseA, $itemA->id, true, null))
        ->toThrow(CrossTenantReferenceException::class);
});
