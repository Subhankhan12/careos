<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Services\AdmissionService;
use Modules\Hospital\Services\BedService;
use Modules\Hospital\Services\WardService;
use Modules\Nursing\Models\Visit;
use Modules\Nursing\Models\VisitAttachment;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientAccessReport;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Resource as BookableResource;

uses(RefreshDatabase::class);

/*
| QA-FIX.12a — FAMILY 4, the remaining unrecorded disclosures (`P9-H2`, `P10-H5`, `QF10a-H1`).
|
| THE BOUNDARY IS D-222's AND IS NOT RE-OPENED HERE: a row belongs in the patient's log when it records
| **this patient's record being made visible or available to someone**. All three findings are plainly
| inside it — a census of who is in which bed, the content of a patient's own messages, and a home-visit
| photo leaving the system. Nothing here touches the two classes that gate REJECTED with reasons
| (`referral.sent`, because CareOS transmits nothing; `notification.sent`, a message about care).
|
| NO SECOND AUDIT PATH, AND NO NEW ACTION STRING. Every fix uses the EXISTING `auditRead()` path, so the
| action stays `read` — already in `PatientAccessReport::DISCLOSURE_ACTIONS`. **This is QA-FIX.10a's own
| correction carried forward:** its first attempt gave the rows a bespoke action, which was well-formed,
| hash-chained and INVISIBLE to the report — closing half the finding while reading as complete. The
| mutation test below pins exactly that difference.
*/

function drpTenant(string $slug): Tenant
{
    $t = Tenant::query()->create(['name' => 'DRP '.$slug, 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($t);

    return $t;
}

function drpStaff(Tenant $tenant, string $roleKey = 'org_admin'): User
{
    $u = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    $r = Role::query()->where('key', $roleKey)->first();
    if ($r !== null) {
        RoleAssignment::query()->firstOrCreate(['user_id' => $u->id, 'role_id' => $r->id]);
    }

    return $u;
}

function drpPatient(string $first, string $last): Patient
{
    return app(PatientService::class)->create([
        'first_name' => $first, 'last_name' => $last, 'date_of_birth' => '1970-05-05', 'sex' => 'female',
    ]);
}

/** The portal guard requires a live `portal.access` consent — granted through the real service. */
function drpPortalConsent(Patient $patient): void
{
    if (ConsentTemplate::query()->where('key', 'portal')->doesntExist()) {
        ConsentTemplate::query()->create([
            'key' => 'portal', 'title' => 'Portal Access', 'body' => 'Portal access consent',
            'version' => 1, 'scope_keys' => ['portal.access'], 'is_active' => true,
        ]);
    }
    app(ConsentService::class)->grant($patient, 'portal', 'typed:'.$patient->first_name, User::query()->firstOrFail());
}
/** Rows the patient's own log would return — through the REAL report, not the raw table. */
function drpLogFor(Patient $patient): array
{
    return app(PatientAccessReport::class)->forPatientNewestFirst($patient)
        ->map(fn (object $r): string => (string) (json_decode((string) $r->context, true)['surface'] ?? ''))
        ->all();
}

/**
 * Count audit rows carrying a surface. DECODED, never matched as SQL text: MySQL 8 re-serialises JSON
 * columns (spacing, key order), so a raw-substring or JSON_EXTRACT predicate passes on MariaDB and
 * reddens on CI — the standing repo rule.
 */
function drpSurfaceCount(string $surface): int
{
    return DB::table('audit_events')->where('action', 'read')->pluck('context')
        ->filter(fn ($c): bool => ($c !== null) && (json_decode((string) $c, true)['surface'] ?? null) === $surface)
        ->count();
}
/* ------------------------------------------------------------------ *
 | `P9-H2` — the ward board discloses every admitted patient.          |
 * ------------------------------------------------------------------ */

it('P9-H2: the ward board records one read per ADMITTED patient, and each reaches their own log', function () {
    $tenant = drpTenant('drp-ward');
    $staff = drpStaff($tenant);
    $manager = drpStaff($tenant, 'bed_manager');
    $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
    $ward = app(WardService::class)->create($manager, $branch->id, 'Innere Medizin', 'IM');

    // TWO admitted patients on purpose: a one-patient fixture cannot tell "one row per patient" from
    // "one row listing them", which is the whole multi-patient resolution (D-189, D-221).
    $alpha = drpPatient('Anna', 'Alpha');
    $beta = drpPatient('Bruno', 'Beta');
    $stranger = drpPatient('Clara', 'Control'); // never admitted — the scoping control

    $profile = StaffProfile::query()->create([
        'first_name' => 'Ada', 'last_name' => 'Admin', 'display_name' => 'Dr. Ada Admin',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id,
    ]);

    $admissions = app(AdmissionService::class);
    foreach ([[$alpha, '1'], [$beta, '2']] as [$patient, $label]) {
        $bed = app(BedService::class)->create($manager, $ward, $label, Bed::TYPE_GENERAL);
        $admissions->admit($staff, $patient, $bed, $profile, Stay::TYPE_ELECTIVE);
    }

    $before = DB::table('audit_events')->where('action', 'read')->count();

    app(TenantContext::class)->forget();
    $this->actingAs($staff)->get('/hospital/wards')->assertOk();

    // BEFORE THE FIX this stayed at zero — the finding's own measurement (D-182).
    expect(DB::table('audit_events')->where('action', 'read')->count())->toBeGreaterThan($before)
        ->and(drpSurfaceCount('ward_board'))->toBe(2);

    // ...and each reaches the RIGHT patient's own log, through the real report.
    expect(drpLogFor($alpha))->toContain('ward_board')
        ->and(drpLogFor($beta))->toContain('ward_board');

    // THE SCOPING CONTROL: a patient who is not on the board gets nothing.
    expect(drpLogFor($stranger))->not->toContain('ward_board');
});

it('P9-H2: an empty ward discloses nobody and writes nothing', function () {
    $tenant = drpTenant('drp-empty');
    $staff = drpStaff($tenant);
    $manager = drpStaff($tenant, 'bed_manager');
    $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
    $ward = app(WardService::class)->create($manager, $branch->id, 'Empty', 'EMP');
    app(BedService::class)->create($manager, $ward, '1', Bed::TYPE_GENERAL);

    app(TenantContext::class)->forget();
    $this->actingAs($staff)->get('/hospital/wards')->assertOk();

    /*
     * THE CARVE-OUT, PROVEN RATHER THAN ASSERTED (D-184). Only OCCUPIED beds are audited. An absence
     * assertion over an empty board would be vacuous, so the test above supplies the non-empty case and
     * this one supplies its complement.
     */
    expect(drpSurfaceCount('ward_board'))->toBe(0);
});

/* ------------------------------------------------------------------ *
 | `P10-H5` — two portal surfaces disclosed and recorded nothing.      |
 * ------------------------------------------------------------------ */

it('P10-H5: portal messages and telehealth each record a read that reaches the patient own log', function () {
    $tenant = drpTenant('drp-portal');
    drpStaff($tenant);
    $patient = drpPatient('Petra', 'Portal');
    $other = drpPatient('Otto', 'Other');

    drpPortalConsent($patient);

    $account = PortalAccount::query()->create([
        'patient_id' => $patient->id, 'email' => 'petra@example.test',
        'password' => bcrypt('secret-password'), 'status' => PortalAccount::STATUS_ACTIVE,
    ]);

    app(TenantContext::class)->forget();
    Auth::guard('patient')->setUser($account);

    foreach ([['portal.messages', 'portal_messages'], ['portal.telehealth', 'portal_telehealth']] as [$route, $surface]) {
        $this->withSession(['portal_tenant_id' => $tenant->id])->get(route($route))->assertOk();
    }

    // BEFORE THE FIX these two surfaces were the ONLY portal pages writing nothing — the finding drove
    // all eight and found six recorded, two not.
    $log = drpLogFor($patient);
    expect($log)->toContain('portal_messages')->toContain('portal_telehealth');

    // THE SCOPING CONTROL.
    expect(drpLogFor($other))->not->toContain('portal_messages')
        ->and(drpLogFor($other))->not->toContain('portal_telehealth');
});

/* ------------------------------------------------------------------ *
 | `QF10a-H1` — a home-visit photo left with no row at all.            |
 * ------------------------------------------------------------------ */

it('QF10a-H1: downloading a home-visit attachment records a read that reaches the patient own log', function () {
    $tenant = drpTenant('drp-attach');
    $nurse = drpStaff($tenant, 'nurse');
    $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN', 'timezone' => 'Europe/Zurich']);
    $patient = drpPatient('Nadja', 'Nursing');
    $other = drpPatient('Oskar', 'Other');

    $profile = StaffProfile::query()->create([
        'first_name' => 'Nina', 'last_name' => 'Nurse', 'display_name' => 'Nina Nurse',
        'profession' => 'nurse', 'primary_branch_id' => $branch->id, 'user_id' => $nurse->id,
    ]);
    $resource = BookableResource::query()->create([
        'type' => BookableResource::TYPE_PRACTITIONER, 'name' => 'Nina Nurse',
        'branch_id' => $branch->id, 'staff_profile_id' => $profile->id, 'active' => true,
    ]);
    $visit = Visit::query()->create([
        'patient_id' => $patient->id, 'branch_id' => $branch->id, 'resource_id' => $resource->id,
        'scheduled_start_at' => now(), 'status' => Visit::STATUS_SCHEDULED,
        'client_visit_uuid' => (string) Str::uuid(),
    ]);

    Storage::disk('local')->put('visits/qa12-photo.jpg', 'not-a-real-image');
    $attachment = VisitAttachment::query()->create([
        'visit_id' => $visit->id, 'patient_id' => $patient->id, 'type' => VisitAttachment::TYPE_PHOTO,
        'storage_path' => 'visits/qa12-photo.jpg', 'mime_type' => 'image/jpeg',
        'size_bytes' => 16, 'captured_at' => now(),
    ]);

    $before = DB::table('audit_events')->where('action', 'read')->count();

    app(TenantContext::class)->forget();
    $token = $nurse->createToken('day-pack', ['nurse:day-pack'])->plainTextToken;
    $this->withHeader('Authorization', 'Bearer '.$token)
        ->get('/api/nurse/attachments/'.$attachment->id.'/download')
        ->assertOk();

    // BEFORE THE FIX: zero. The controller's 45 lines contained no auditRead at all (D-182).
    expect(DB::table('audit_events')->where('action', 'read')->count())->toBeGreaterThan($before)
        ->and(drpLogFor($patient))->toContain('nurse_visit_attachment_download')
        ->and(drpLogFor($other))->not->toContain('nurse_visit_attachment_download');
});

/* ------------------------------------------------------------------ *
 | THE SHAPE ITSELF — one path, no bespoke action, and the export.     |
 * ------------------------------------------------------------------ */

it('carries forward QA-FIX.10a correction: the action is `read`, never a bespoke string', function () {
    /*
     * THE MISTAKE THIS PINS. QA-FIX.10a's first attempt used a bespoke action: well-formed,
     * hash-chained, patient-scoped — and INVISIBLE, because `PatientAccessReport` filters on
     * `DISCLOSURE_ACTIONS`. Mutation-checked: changing any of these four sites to a bespoke action
     * leaves "is audited" green and turns the "reaches the log" assertions RED.
     */
    foreach ([
        'Modules/Hospital/src/Http/Controllers/WardBoardController.php',
        'Modules/Comms/src/Http/Controllers/PortalMessageController.php',
        'Modules/Comms/src/Http/Controllers/PortalTelehealthController.php',
        'Modules/Nursing/src/Http/Controllers/NurseVisitAttachmentController.php',
    ] as $file) {
        $src = (string) file_get_contents(base_path($file));

        // The EXISTING path, and no second one: no direct AuditService::record with a hand-rolled action.
        expect($src)->toContain('auditRead(')
            ->and($src)->not->toContain("'action' =>");
    }

    // And the set itself is unchanged — this part added no action class.
    expect(PatientAccessReport::DISCLOSURE_ACTIONS)->toBe(['read', 'document.shared', 'document.unshared']);
});

it('the export agrees with the screen — one query, so it cannot disagree', function () {
    $tenant = drpTenant('drp-export');
    $staff = drpStaff($tenant);
    $patient = drpPatient('Elsa', 'Export');

    drpPortalConsent($patient);

    $account = PortalAccount::query()->create([
        'patient_id' => $patient->id, 'email' => 'elsa@example.test',
        'password' => bcrypt('secret-password'), 'status' => PortalAccount::STATUS_ACTIVE,
    ]);

    app(TenantContext::class)->forget();
    Auth::guard('patient')->setUser($account);
    $this->withSession(['portal_tenant_id' => $tenant->id])->get(route('portal.messages'))->assertOk();

    Auth::guard('patient')->logout();

    // The nDSG/GDPR subject-access export and the screen share ONE query (PC.P5), so a disclosure that
    // reaches the log must reach the file too. Asserted rather than assumed.
    $csv = $this->actingAs($staff)->get(route('patients.access-log.export', $patient->id))->getContent();

    expect($csv)->toContain('portal_messages')
        ->and($csv)->toContain('occurred_at,action,');
});
