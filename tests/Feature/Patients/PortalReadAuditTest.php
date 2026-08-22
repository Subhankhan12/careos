<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Clinical\Models\Document;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientAccessReport;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * PT.P1 — the patient's access log can finally see the portal.
 *
 * Six portal surfaces disclosed the patient's own record and wrote NO read row, so PC.P5's access
 * log — the screen built to answer "who accessed my data" — was blind to the portal. It was blind
 * to the very row PC.P5's own wireframe draws: "viewed her own summary · portal_home · patient".
 *
 * What is asserted here:
 *  1. EACH of the six surfaces writes EXACTLY ONE read row per render, with actor_type `patient`,
 *     the viewer's own patient id, and the agreed surface name.
 *  2. Those rows appear in PC.P5's access-log query AND its CSV export — one source, so the file a
 *     patient receives cannot disagree with the screen.
 *  3. No row is written against anyone else: a control patient's log stays empty (D-174 — and the
 *     control is only meaningful because the viewer's log is provably non-empty).
 *  4. The guest-route smoke now covers `/portal/login`.
 */

function ptaCtx(): TenantContext
{
    return app(TenantContext::class);
}

/**
 * A portal-enrolled patient with real data on every surface, plus a CONTROL patient who has no
 * portal account and whose log must stay untouched.
 *
 * @return array{tenant: Tenant, staff: User, patient: Patient, account: PortalAccount, control: Patient, appointmentless: bool}
 */
function ptaFixture(): array
{
    $tenant = Tenant::query()->create(['name' => 'Alpha Clinic', 'slug' => 'alpha', 'region' => 'eu', 'status' => 'active']);
    ptaCtx()->set($tenant);

    $staff = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $staff->id,
        'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id,
    ]);

    Branch::query()->create(['name' => 'Main', 'code' => 'MAIN']);

    $patient = app(PatientService::class)->create([
        'first_name' => 'Erika', 'last_name' => 'Baumgartner', 'date_of_birth' => '1954-03-12', 'sex' => 'female',
    ]);
    $control = app(PatientService::class)->create([
        'first_name' => 'Viktor', 'last_name' => 'Odermatt', 'date_of_birth' => '1968-02-11', 'sex' => 'male',
    ]);

    // portal.access is enforced by middleware on every portal page — without it nothing renders.
    ConsentTemplate::query()->create([
        'key' => 'portal', 'title' => 'Portal Access', 'body' => 'Portal access consent',
        'version' => 1, 'scope_keys' => ['portal.access'], 'is_active' => true,
    ]);
    app(ConsentService::class)->grant($patient, 'portal', 'Erika Baumgartner', $staff);

    $account = PortalAccount::query()->create([
        'patient_id' => $patient->id,
        'email' => 'erika.baumgartner@example.test',
        'password' => bcrypt('secret-password'),
        'status' => PortalAccount::STATUS_ACTIVE,
    ]);

    // Real data on the surfaces that list it, so no render is trivially empty.
    Document::query()->create([
        'patient_id' => $patient->id,
        'category' => Document::CATEGORY_LETTER,
        'title' => 'Referral letter',
        'original_filename' => 'referral.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'storage_path' => 'tenants/'.$tenant->id.'/documents/referral.pdf',
        'shared_with_patient' => true,
        'uploaded_by' => $staff->id,
        'uploaded_at' => now(),
    ]);

    return compact('tenant', 'staff', 'patient', 'account', 'control') + ['appointmentless' => true];
}

/**
 * Sign a portal account in the way a REAL request does: set the user on the `patient` guard
 * WITHOUT making it the default guard.
 *
 * `actingAs($account, 'patient')` also calls `shouldUse('patient')`, so `Auth::user()` starts
 * resolving the PortalAccount — and `PlatformAuditContext::actor()` checks `Auth::user()` first,
 * so every audit row comes out `actor_type = user`. In production the default guard stays `web`,
 * `Auth::user()` is null, and the context falls through to the patient branch. Verified in a
 * browser: real portal reads record `actor_type = patient`.
 */
function ptaSignIn($test, PortalAccount $account)
{
    Auth::guard('patient')->setUser($account);

    return $test;
}

/** Every `action='read'` row for this patient carrying the given surface. */
function ptaRows(Patient $patient, string $surface): int
{
    return (int) DB::table('audit_events')
        ->where('action', 'read')
        ->where('patient_id', $patient->id)
        ->where('context', 'like', '%"surface":"'.$surface.'"%')
        ->count();
}

test('each of the six portal surfaces writes EXACTLY ONE read row per render', function () {
    $fx = ptaFixture();

    $surfaces = [
        'portal_home' => route('portal.home'),
        'portal_appointments' => route('portal.appointments'),
        'portal_documents' => route('portal.documents.index'),
        'portal_invoices' => route('portal.invoices'),
        'portal_consents' => route('portal.consents'),
    ];

    foreach ($surfaces as $surface => $url) {
        ptaCtx()->set($fx['tenant']);
        expect(ptaRows($fx['patient'], $surface))->toBe(0, "{$surface} already had a row before the visit");

        ptaCtx()->forget();
        ptaSignIn($this, $fx['account'])
            ->withSession(['portal_tenant_id' => $fx['tenant']->id])
            ->get($url)
            ->assertOk();

        ptaCtx()->set($fx['tenant']);
        // ONE row per render — not zero, and not one per listed item.
        expect(ptaRows($fx['patient'], $surface))->toBe(1, "{$surface} wrote ".ptaRows($fx['patient'], $surface).' rows for one render');

        // A second render writes exactly one more: the row tracks the disclosure, not the session.
        ptaCtx()->forget();
        ptaSignIn($this, $fx['account'])
            ->withSession(['portal_tenant_id' => $fx['tenant']->id])
            ->get($url)
            ->assertOk();

        ptaCtx()->set($fx['tenant']);
        expect(ptaRows($fx['patient'], $surface))->toBe(2, "{$surface} does not write one row per render");

        /*
         * ...AND THE ROW IS AGAINST THE VIEWER, NOT SOMEONE ELSE. Counting rows for the viewer
         * is not enough on its own: a surface that audits the WRONG patient still leaves the
         * viewer's count looking right whenever the two happen to coincide. Assert the id, and
         * assert the control patient never accumulates a row from a visit that was not theirs.
         */
        expect(ptaRows($fx['control'], $surface))
            ->toBe(0, "{$surface} wrote a row against a patient who was never viewed");
    }
});

test('the check-in surface writes exactly one read row per request', function () {
    $fx = ptaFixture();

    ptaCtx()->set($fx['tenant']);
    expect(ptaRows($fx['patient'], 'portal_checkin'))->toBe(0);

    /*
     * The appointment id is deliberately bogus: check-in 404s on it, but the READ row is written
     * before that — the patient's record was reached either way, which is the disclosure the log
     * exists to record.
     */
    ptaCtx()->forget();
    ptaSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->post(route('portal.check-in'), ['appointment_id' => '01NOTAREALAPPOINTMENTID0001'])
        ->assertNotFound();

    ptaCtx()->set($fx['tenant']);
    expect(ptaRows($fx['patient'], 'portal_checkin'))->toBe(1);
});

test('the rows carry actor_type patient and the viewer own id — and reach PC.P5 access log and export', function () {
    $fx = ptaFixture();

    ptaCtx()->forget();
    ptaSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->get(route('portal.home'))
        ->assertOk();

    ptaCtx()->set($fx['tenant']);

    $row = DB::table('audit_events')
        ->where('action', 'read')
        ->where('patient_id', $fx['patient']->id)
        ->where('context', 'like', '%portal_home%')
        ->first();

    expect($row)->not->toBeNull()
        // The portal records the PATIENT as the actor — this is what makes the row legible in
        // their own access log rather than looking like a staff read.
        ->and($row->actor_type)->toBe('patient')
        ->and($row->actor_id)->toBe((string) $fx['account']->id)
        ->and($row->resource_type)->toBe('patient')
        ->and($row->patient_id)->toBe($fx['patient']->id);

    // 1) It appears in PC.P5's access-log query.
    $report = app(PatientAccessReport::class)->forPatientNewestFirst($fx['patient']);
    $surfaces = $report->map(fn (object $r): string => (string) $r->context)->filter(fn (string $c): bool => str_contains($c, 'portal_home'));
    expect($surfaces)->not->toBeEmpty('the portal row is missing from the PC.P5 access log');

    // 2) ...and in its CSV export — the SAME query, so the file cannot disagree with the screen.
    $admin = $fx['staff'];
    ptaCtx()->forget();
    $csv = $this->actingAs($admin)
        ->get(route('patients.access-log.export', $fx['patient']->id))
        ->assertOk()
        ->getContent();

    expect($csv)->toContain('portal_home')
        ->and($csv)->toContain('patient');
});

test('no row is written against anyone else — the control patient log stays empty', function () {
    $fx = ptaFixture();

    ptaCtx()->forget();
    foreach ([route('portal.home'), route('portal.appointments'), route('portal.consents')] as $url) {
        ptaSignIn($this, $fx['account'])
            ->withSession(['portal_tenant_id' => $fx['tenant']->id])
            ->get($url)
            ->assertOk();
    }

    ptaCtx()->set($fx['tenant']);

    // POSITIVE CONTROL (D-174): the viewer's log really did fill up — otherwise "the control is
    // empty" would be true of a run that recorded nothing at all.
    $viewer = app(PatientAccessReport::class)->forPatientNewestFirst($fx['patient']);
    expect($viewer->count())->toBeGreaterThanOrEqual(3);

    $control = app(PatientAccessReport::class)->forPatientNewestFirst($fx['control']);
    expect($control)->toBeEmpty('a portal visit wrote a row against a patient who was never viewed');
});

test('the guest-route smoke now covers the portal sign-in page', function () {
    $smoke = (string) file_get_contents(base_path('tests/Feature/Smoke/RouteSmokeTest.php'));

    // POSITIVE CONTROL: the guest-route block really is the one being inspected.
    expect($smoke)->toContain('every GUEST route renders for an anonymous visitor')
        ->and($smoke)->toContain("'forgot-password' => '/forgot-password'");

    // The patient's own entry point is now in that list.
    expect($smoke)->toContain("'portal.login' => '/portal/login'");
});
