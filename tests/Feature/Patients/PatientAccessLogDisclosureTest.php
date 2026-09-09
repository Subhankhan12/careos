<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Services\AuditService;
use Modules\Clinical\Models\Document;
use Modules\Clinical\Services\DocumentService;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientAccessReport;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * QA-FIX.10b — `P9-C2`: a record RELEASE was invisible on the screen built to show exactly that.
 *
 * Driven in Phase 9: a doctor released Nadia Lüthi's referral letter to her patient portal (HTTP 200,
 * `shared_with_patient: true`) and neither the Patient-360 access tab nor the dedicated PC.P5 screen
 * showed it. The release WAS in the ledger — a correct, patient-scoped, hash-chained `document.shared`
 * row — and `PatientAccessReport`'s query bound the literal `'read'`, so the one category a
 * subject-access request is actually about was filtered out of the artifact built to satisfy it.
 *
 * THIS FILE PINS THE BOUNDARY IN BOTH DIRECTIONS, which is the whole point. Widening a whitelist of
 * one to a whitelist of three is only an improvement if the three are the right three and the
 * exclusions are deliberate. So: the three disclosure classes must APPEAR, and named activity actions
 * must be ABSENT ON PURPOSE. A test that only asserted the release appears would pass just as well
 * for "return every row that has a patient_id", which would turn a subject-access artifact into an
 * activity feed (982 of the live ledger's rows carry a patient id; 26 are disclosures).
 */

function padTenant(): Tenant
{
    $tenant = Tenant::query()->create(['name' => 'Disclosure Care', 'slug' => 'disclosure-care', 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($tenant);

    return $tenant;
}

/** @return array{tenant: Tenant, actor: User, patient: Patient, other: Patient} */
function padFixture(): array
{
    $tenant = padTenant();
    $actor = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    foreach (['doctor', 'org_admin'] as $key) {
        $role = Role::query()->where('key', $key)->first();
        if ($role !== null) {
            RoleAssignment::query()->firstOrCreate(['user_id' => $actor->id, 'role_id' => $role->id]);
        }
    }

    $patients = app(PatientService::class);
    $patient = $patients->create(['first_name' => 'Nadia', 'last_name' => 'Luethi', 'date_of_birth' => '1985-03-03', 'sex' => 'female']);
    // A SECOND patient, so "the release appears" cannot pass by returning every row in the tenant.
    $other = $patients->create(['first_name' => 'Otto', 'last_name' => 'Other', 'date_of_birth' => '1975-05-05', 'sex' => 'male']);

    return compact('tenant', 'actor', 'patient', 'other');
}

/** Write an audit row directly — the point is the ACTION, not the path that produced it. */
function padRow(string $action, Patient $patient, User $actor, array $context = []): void
{
    app(AuditService::class)->record([
        'actor_type' => 'user',
        'actor_id' => (string) $actor->id,
        'action' => $action,
        'resource_type' => 'document',
        // A short id on purpose: `audit_events.resource_id` is a ULID-width column.
        'resource_id' => str_pad((string) abs(crc32($action)), 26, '0', STR_PAD_LEFT),
        'patient_id' => (string) $patient->id,
        'context' => $context,
    ]);
}

function padActions(Patient $patient): array
{
    return app(PatientAccessReport::class)
        ->forPatientNewestFirst($patient)
        ->pluck('action')
        ->all();
}

it('shows a document released to the patient portal — the exact row P9-C2 could not see', function () {
    ['actor' => $actor, 'patient' => $patient] = padFixture();

    padRow('document.shared', $patient, $actor);

    // BEFORE THE FIX this was empty: the row existed, and the report's `action = 'read'` hid it.
    expect(padActions($patient))->toContain('document.shared');
});

it('shows the withdrawal of a release beside the release itself', function () {
    ['actor' => $actor, 'patient' => $patient] = padFixture();

    padRow('document.shared', $patient, $actor);
    padRow('document.unshared', $patient, $actor);

    // A log that shows a release and never its withdrawal asserts an availability that has ended.
    expect(padActions($patient))->toContain('document.shared')->toContain('document.unshared');
});

it('still shows plain reads, which is what it showed before', function () {
    ['actor' => $actor, 'patient' => $patient] = padFixture();

    padRow('read', $patient, $actor);

    // THE POSITIVE CONTROL (D-174): widening the set must not drop the class it already had.
    expect(padActions($patient))->toContain('read');
});

it('does not turn the log into an activity feed — named activity actions stay out on purpose', function () {
    ['actor' => $actor, 'patient' => $patient] = padFixture();

    /*
     * THE OTHER DIRECTION, AND THE REASON THIS TEST EXISTS. Each of these carries a patient_id and
     * each is deliberately excluded: they are activity ON the record, not disclosure OF it. The two
     * at the end are the borderline cases the study examined and rejected with reasons —
     * `referral.sent` because CareOS transmits nothing (listing it would assert a disclosure the
     * product did not make) and `notification.sent` because it is a message about care.
     */
    $excluded = [
        'charge.captured', 'charge.validated', 'planned_visit.materialized', 'visit.check_in',
        'consent.granted', 'consent.withdrawn', 'document.uploaded', 'document.deleted',
        'admission.discharged', 'appointment.booked', 'referral.sent', 'notification.sent',
    ];
    foreach ($excluded as $action) {
        padRow($action, $patient, $actor);
    }
    padRow('read', $patient, $actor);

    $actions = padActions($patient);

    expect($actions)->toBe(['read']);
    foreach ($excluded as $action) {
        expect($actions)->not->toContain($action);
    }
});

it('keeps every query in the class on one definition of the set', function () {
    ['actor' => $actor, 'patient' => $patient] = padFixture();

    padRow('read', $patient, $actor);
    padRow('document.shared', $patient, $actor);
    padRow('charge.captured', $patient, $actor);

    /*
     * THE ROW LIST AND THE TWO COUNTERS MUST AGREE. They are three separate SQL statements, and the
     * screen prints the counters as a headline ("N recorded accesses by M distinct actors"), so a
     * counter left on the old filter would contradict the list beneath it — the failure PC.P5's
     * one-query rule exists to prevent, reappearing as three queries sharing one constant.
     */
    $report = app(PatientAccessReport::class);

    expect($report->forPatientNewestFirst($patient))->toHaveCount(2)
        ->and($report->actorTypeCountsFor($patient)->sum('total'))->toBe(2)
        ->and($report->distinctActorCountFor($patient))->toBe(1);
});

it('does not leak one patient disclosures belonging to another', function () {
    ['actor' => $actor, 'patient' => $patient, 'other' => $other] = padFixture();

    padRow('document.shared', $other, $actor);

    // THE SCOPING CONTROL: widening the ACTION filter must not widen the PATIENT filter.
    expect(padActions($patient))->toBe([])
        ->and(padActions($other))->toBe(['document.shared']);
});

it('carries the action to the screen and to the subject-access export', function () {
    ['actor' => $actor, 'patient' => $patient] = padFixture();

    padRow('read', $patient, $actor);
    padRow('document.shared', $patient, $actor);

    $screen = $this->actingAs($actor)->get(route('patients.access-log', $patient->id));
    $screen->assertOk();

    /*
     * THE RENDERED VALUE, NOT JUST THE COLUMN (the QA-FIX.5a lesson). The Vue component used to
     * print `t('…readAction')` — the word "read" HARDCODED — so a release reaching the page would
     * have been LABELLED A READ: the wrong fact, on the one screen whose job is this fact.
     */
    /*
     * The DISTINCT actions, not the row count: opening this page is itself a disclosure and writes
     * its own `read` row before rendering (that is PC.P5's self-audit, not noise), so the list
     * legitimately holds two reads. What must be true is that BOTH KINDS reach the surface.
     */
    $rows = $screen->viewData('page')['props']['rows'];
    expect(collect($rows)->pluck('action')->unique()->sort()->values()->all())->toBe(['document.shared', 'read']);

    // A plain response, not a stream — this export builds the whole CSV in memory before returning.
    $csv = $this->actingAs($actor)->get(route('patients.access-log.export', $patient->id))->getContent();

    $this->assertStringContainsString('occurred_at,action,', $csv, 'the export must have an action column');
    $this->assertStringContainsString('document.shared', $csv, 'and the release must be in the file, not only on screen');
});

it('releases a document through the real service and finds it in the patient log', function () {
    ['actor' => $actor, 'patient' => $patient] = padFixture();

    /*
     * END TO END THROUGH THE REAL PATH, because every assertion above writes its audit row directly.
     * `P9-C2` was a mismatch between what the RELEASE PATH writes and what the REPORT reads, and a
     * fixture that writes both halves itself cannot detect that mismatch. This one releases a real
     * document through `DocumentService` and asks the report — the same two components the finding
     * put on either side of the gap.
     */
    $document = Document::query()->create([
        'patient_id' => $patient->id,
        'category' => 'referral',
        'title' => 'Referral letter',
        'original_filename' => 'referral.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'storage_path' => 'tenants/x/documents/referral.pdf',
        'uploaded_by' => $actor->id,
        'uploaded_at' => now(),
    ]);

    /*
     * The real path refuses without portal consent, so the fixture grants it through the real
     * service — a template whose SCOPE is `portal.access`, which is what `shareWithPatient` checks.
     * Granting it properly matters: a fixture that bypassed the consent gate would be releasing a
     * document by a route the product does not have.
     */
    ConsentTemplate::query()->create([
        'key' => 'portal',
        'title' => 'Patient portal access',
        'body' => 'I consent to accessing my record through the patient portal.',
        'version' => 1,
        'scope_keys' => ['portal.access'],
        'is_active' => true,
    ]);
    app(ConsentService::class)->grant($patient, 'portal', 'typed:Nadia Luethi', $actor);

    app(DocumentService::class)->shareWithPatient($document, $actor);

    expect(DB::table('audit_events')->where('action', 'document.shared')->where('patient_id', $patient->id)->exists())->toBeTrue()
        ->and(padActions($patient))->toContain('document.shared');
});
