<?php

use Database\Seeders\DemoClinicSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Hospital\Models\Bed;
use Modules\Hospital\Models\Stay;
use Modules\Hospital\Services\AdmissionService;
use Modules\Hospital\Services\BedService;
use Modules\Hospital\Services\WardService;
use Modules\Import\Models\ImportBatch;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Services\ConsentService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * FIX.5 — Route reachability smoke through the REAL middleware stack.
 *
 * The QA audit's C-1 (billing/import detail routes 500'd in the browser) shipped green
 * because the unit/feature suite pre-seeded the TenantContext singleton before each
 * request, masking the middleware ordering (SubstituteBindings runs before
 * IdentifyTenantFromUser). This suite is the systemic guard: for a superset role it drives
 * EVERY major GET route through the full HTTP kernel — with the tenant context FORGOTTEN
 * before each request, exactly like an independent browser request — and asserts each
 * returns 200 (never a 500 or a 419). If any route regresses to a request-time 500 (the
 * C-1 class), this fails in CI before the change can ship. Per-role RBAC and portal
 * reachability are smoked too. It is deliberately shallow (status-of-page, not deep
 * assertions) and fast; a new page = one line in the route list below.
 *
 * (Request-level, not a headless browser: this runs in the existing MySQL-8 Pest job on
 *  every push, is deterministic, and exercises the identical middleware pipeline that C-1
 *  broke — no artisan-serve / browser-install / TOTP-timing flakiness. See D-093 / FIX.5.)
 */

function smokeCtx(): TenantContext
{
    return app(TenantContext::class);
}

/** Seed the demo clinic and resolve the users + real ids the routes need. */
function smokeSeed(object $test): array
{
    // Fake BEFORE seeding so the invoice PDFs the demo issues land on the fake disk (the
    // PDF download route reads Storage::disk('local')).
    Storage::fake('local');
    $test->seed(DemoClinicSeeder::class);

    $tenant = smokeCtx()->current(); // the demo seeder leaves context set to the clinic tenant

    $users = [];
    foreach (['org_admin', 'doctor', 'nurse', 'coordinator', 'reception', 'billing'] as $role) {
        $roleId = Role::query()->where('key', $role)->value('id');
        $userId = RoleAssignment::query()->where('role_id', $roleId)->value('user_id');
        $users[$role] = User::query()->findOrFail($userId);
    }

    $invoice = Invoice::query()->where('series', Invoice::SERIES_INVOICE)->where('status', Invoice::STATUS_ISSUED)->firstOrFail();
    $creditNote = Invoice::query()->where('series', Invoice::SERIES_CREDIT_NOTE)->firstOrFail();
    $payment = Payment::query()->firstOrFail();
    $patient = Patient::query()->findOrFail($invoice->patient_id);
    $encounter = Encounter::query()->firstOrFail();
    $note = ClinicalNote::query()->firstOrFail();

    // The demo has no import batch (import is onboarding, not operational) — make one so the
    // C-1-class import detail route is covered.
    $batch = ImportBatch::query()->create([
        'type' => 'patients',
        'original_filename' => 'smoke.csv',
        'storage_path' => 'tenants/'.$tenant->id.'/imports/smoke.csv',
        'status' => ImportBatch::STATUS_UPLOADED,
        'row_count' => 2,
        'created_by' => (string) $users['org_admin']->id,
    ]);

    // HOSPITAL.G2: the demo clinic has no inpatient data — create a ward + bed and admit a patient
    // so the ADT admission-detail route (a C-1-class request-time surface) is covered. org_admin
    // holds ward.manage / bed.manage / admission.manage.
    $admin = $users['org_admin'];
    $branch = Branch::query()->where('active', true)->firstOrFail();
    $ward = app(WardService::class)->create($admin, $branch->id, 'Smoke Ward', 'SMK');
    $bed = app(BedService::class)->create($admin, $ward, '1', Bed::TYPE_GENERAL);
    $clinician = StaffProfile::query()->firstOrFail();
    $stay = app(AdmissionService::class)->admit($admin, $patient, $bed, $clinician, Stay::TYPE_ELECTIVE);

    return compact('tenant', 'users', 'invoice', 'creditNote', 'payment', 'patient', 'encounter', 'note', 'batch', 'stay');
}

/** One request through the real stack with NO ambient tenant context (the C-1 condition). */
function smokeStatus(object $test, User $user, string $url): int
{
    smokeCtx()->forget();

    return $test->actingAs($user)->get($url)->status();
}

test('every major staff route is reachable through the real middleware stack (200, never a request-time 500)', function () {
    $fx = smokeSeed($this);
    $admin = $fx['users']['org_admin']; // superset role — reaches every route below

    // Static pages (no id).
    $routes = [
        'landing' => '/app',
        'patients.index' => '/patients',
        'patients.register' => '/patients/register',
        'day-board' => '/scheduling/day-board',
        'dispatch' => '/nursing/dispatch',
        'competencies' => '/nursing/competencies',
        'inbox' => '/comms/inbox',
        'orders.review' => '/clinical/orders/review',
        'snippets' => '/clinical/snippets',
        'orderable-items' => '/clinical/orderable-items',
        'billing.invoices' => '/billing/invoices',
        'billing.aging' => '/billing/aging',
        'billing.credit-notes' => '/billing/credit-notes',
        'billing.payments' => '/billing/payments',
        'billing.payments.record' => '/billing/payments/record',
        'billing.new-invoice' => '/billing/new-invoice',
        'billing.dunning' => '/billing/dunning',
        'dental.fee-schedule' => '/dental/fee-schedule',
        'dental.index' => '/dental',
        'reporting' => '/reporting',
        'governance' => '/governance',
        'governance.approvals' => '/governance/approvals',
        'governance.kb' => '/governance/kb',
        'telehealth' => '/telehealth',
        'imports.index' => '/imports',
        'imports.create' => '/imports/create',
        'admin.kiosks' => '/admin/kiosks',
        'settings' => '/settings',
        'admin.roles' => '/admin/roles',
        'admin.branches' => '/admin/branches',
        // Detail pages (real ids) — the billing/import ones are the C-1 regression surface.
        'patient.show' => '/patients/'.$fx['patient']->id,
        'chart' => '/clinical/chart/'.$fx['patient']->id,
        'dental.chart' => '/dental/chart/'.$fx['patient']->id,
        'dental.perio' => '/dental/perio/'.$fx['patient']->id,
        'dental.diagnoses' => '/dental/diagnoses/'.$fx['patient']->id,
        'dental.imaging' => '/dental/images/'.$fx['patient']->id,
        'dental.plans' => '/dental/plans/'.$fx['patient']->id,
        'encounter.show' => '/clinical/encounters/'.$fx['encounter']->id,
        'note.show' => '/clinical/notes/'.$fx['note']->id,
        'note.edit' => '/clinical/notes/'.$fx['note']->id.'/edit',
        'invoice.show (C-1)' => '/billing/invoices/'.$fx['invoice']->id,
        'invoice.pdf' => '/billing/invoices/'.$fx['invoice']->id.'/pdf',
        'credit-note.show (C-1)' => '/billing/credit-notes/'.$fx['creditNote']->id,
        'payment.show (C-1)' => '/billing/payments/'.$fx['payment']->id,
        'import.show (C-1)' => '/imports/'.$fx['batch']->id,
        'hospital.admission (C-1)' => '/hospital/admissions/'.$fx['stay']->id,
        'hospital.wards' => '/hospital/wards',
        'hospital.stay-chart' => '/hospital/admissions/'.$fx['stay']->id.'/chart',
        'hospital.handover' => '/hospital/admissions/'.$fx['stay']->id.'/handover',
        'hospital.discharge-summary' => '/hospital/admissions/'.$fx['stay']->id.'/discharge-summary',
        'pharmacy.formulary' => '/pharmacy/formulary',
        'pharmacy.patient-medications' => '/pharmacy/patients/'.$fx['patient']->id.'/medications',
        'pharmacy.patient-emar' => '/pharmacy/patients/'.$fx['patient']->id.'/emar',
        'pharmacy.inventory' => '/pharmacy/inventory',
        'pharmacy.patient-dispensing' => '/pharmacy/patients/'.$fx['patient']->id.'/dispensing',
    ];

    $failures = [];
    foreach ($routes as $label => $url) {
        $status = smokeStatus($this, $admin, $url);
        if ($status !== 200) {
            $failures[] = "{$label} [{$url}] -> {$status}";
        }
    }

    // Public booking is outside the auth group and resolves its tenant from the slug.
    smokeCtx()->forget();
    $publicStatus = $this->get('/book/'.$fx['tenant']->slug)->status();
    if ($publicStatus !== 200) {
        $failures[] = "public.booking [/book/{$fx['tenant']->slug}] -> {$publicStatus}";
    }

    expect(implode("\n", $failures))->toBe('');
});

test('per-role RBAC smoke: each role reaches its pages (200) and is denied others (403) by URL', function () {
    $fx = smokeSeed($this);
    $u = $fx['users'];
    $patientUrl = '/patients/'.$fx['patient']->id;

    // [user, url, expected status]
    $cases = [
        // doctor: clinical yes; billing/reporting no.
        [$u['doctor'], '/patients', 200],
        [$u['doctor'], '/clinical/chart/'.$fx['patient']->id, 200],
        [$u['doctor'], '/billing/invoices', 403],
        [$u['doctor'], '/reporting', 403],
        // DENTAL.G2: the odontogram is patient.view-gated — the dentist (doctor) reaches it,
        // a non-dental role without patient.view (billing) is denied.
        [$u['doctor'], '/dental/chart/'.$fx['patient']->id, 200],
        [$u['billing'], '/dental/chart/'.$fx['patient']->id, 403],
        // DENTAL.G6: the perio chart is patient.view-gated — the dentist reaches it; billing
        // (no patient.view) is denied.
        [$u['doctor'], '/dental/perio/'.$fx['patient']->id, 200],
        [$u['billing'], '/dental/perio/'.$fx['patient']->id, 403],
        // DENTAL.G7: the diagnosis page is patient.view-gated — the dentist reaches it; billing
        // (no patient.view) is denied.
        [$u['doctor'], '/dental/diagnoses/'.$fx['patient']->id, 200],
        [$u['billing'], '/dental/diagnoses/'.$fx['patient']->id, 403],
        // DENTAL.G8: the imaging page is patient.view-gated — the dentist reaches it; billing
        // (no patient.view) is denied.
        [$u['doctor'], '/dental/images/'.$fx['patient']->id, 200],
        [$u['billing'], '/dental/images/'.$fx['patient']->id, 403],
        // DENTAL.G5: the treatment-plan page is patient.view-gated (the dentist reads/builds it);
        // billing (no patient.view) is denied.
        [$u['doctor'], '/dental/plans/'.$fx['patient']->id, 200],
        [$u['billing'], '/dental/plans/'.$fx['patient']->id, 403],
        // nurse: patients yes; billing no.
        [$u['nurse'], '/patients', 200],
        [$u['nurse'], '/billing/invoices', 403],
        // coordinator: dispatch + reporting yes; billing + inbox no.
        [$u['coordinator'], '/nursing/dispatch', 200],
        [$u['coordinator'], '/reporting', 200],
        [$u['coordinator'], '/billing/invoices', 403],
        [$u['coordinator'], '/comms/inbox', 403],
        // reception: inbox + patients yes; billing (the C-1 surface) + reporting no.
        [$u['reception'], '/comms/inbox', 200],
        [$u['reception'], '/patients', 200],
        [$u['reception'], '/billing/invoices', 403],
        [$u['reception'], '/reporting', 403],
        // billing: billing yes; patients (no patient.view) no.
        [$u['billing'], '/billing/invoices', 200],
        [$u['billing'], $patientUrl, 403],
        // DENTAL.G3: the dental fee schedule is billing.manage — the billing role + org_admin
        // reach it; reception (no billing.manage) is denied.
        [$u['billing'], '/dental/fee-schedule', 200],
        [$u['reception'], '/dental/fee-schedule', 403],
        // DENTAL.G9: the dental section landing is dental.chart-gated (the dentist reaches it);
        // reception (patient.view but NOT dental.chart) is denied — same gate as the nav entry.
        [$u['doctor'], '/dental', 200],
        [$u['reception'], '/dental', 403],
        // HOSPITAL.G3: the ward board is patient.view-gated (inpatient clinical staff incl. ward
        // nurses reach it); billing (no patient.view) is denied.
        [$u['doctor'], '/hospital/wards', 200],
        [$u['billing'], '/hospital/wards', 403],
        // HOSPITAL.G4: the bedside stay-chart is patient.view-gated (read-logged); billing is denied.
        [$u['doctor'], '/hospital/admissions/'.$fx['stay']->id.'/chart', 200],
        [$u['billing'], '/hospital/admissions/'.$fx['stay']->id.'/chart', 403],
        // HOSPITAL.G5: the shift handover is patient.view-gated (read-logged); billing is denied.
        [$u['doctor'], '/hospital/admissions/'.$fx['stay']->id.'/handover', 200],
        [$u['billing'], '/hospital/admissions/'.$fx['stay']->id.'/handover', 403],
        // settings + roles are admin.manage only — reception is denied, org_admin reaches them.
        [$u['reception'], '/settings', 403],
        [$u['reception'], '/admin/roles', 403],
        [$u['reception'], '/admin/branches', 403],
        [$u['org_admin'], '/settings', 200],
        [$u['org_admin'], '/admin/roles', 200],
        [$u['org_admin'], '/admin/branches', 200],
        // Governance + AI approval queue are audit.view / ai.manage — org_admin holds both,
        // reception holds neither. (W9: the two most safety-sensitive admin surfaces.)
        [$u['reception'], '/governance', 403],
        [$u['reception'], '/governance/approvals', 403],
        [$u['org_admin'], '/governance', 200],
        [$u['org_admin'], '/governance/approvals', 200],
        // W10: KB admin is ai.manage (org_admin only); staff telehealth is encounter.manage
        // (clinicians). reception has neither; a doctor reaches telehealth but not KB.
        [$u['reception'], '/governance/kb', 403],
        [$u['reception'], '/telehealth', 403],
        [$u['org_admin'], '/governance/kb', 200],
        [$u['org_admin'], '/telehealth', 200],
        [$u['doctor'], '/telehealth', 200],
        [$u['doctor'], '/governance/kb', 403],
        // org_admin is a tenant admin, NOT a super-admin: the platform shell is denied.
        [$u['org_admin'], '/admin', 403],
    ];

    $failures = [];
    foreach ($cases as [$user, $url, $expected]) {
        $status = smokeStatus($this, $user, $url);
        if ($status !== $expected) {
            $failures[] = "{$url} as {$user->name} -> {$status} (expected {$expected})";
        }
    }

    // W8c: the resource WRITE routes go through the same admin.manage gate + middleware
    // stack. Drive the store route with the tenant context forgotten (the C-1 condition):
    // reception is denied (403); org_admin reaches it and redirects (302, never a 500).
    $branch = Branch::query()->where('active', true)->firstOrFail();
    foreach ([['reception', 403], ['org_admin', 302]] as [$role, $expected]) {
        smokeCtx()->forget();
        $status = $this->actingAs($u[$role])
            ->post("/admin/branches/{$branch->id}/resources", ['name' => 'Smoke Room', 'type' => 'room'])
            ->status();
        if ($status !== $expected) {
            $failures[] = "resource.store as {$role} -> {$status} (expected {$expected})";
        }
    }

    // DENTAL.G4: the perform-a-procedure write route is dental.chart-gated (clinical). Reception
    // (no dental.chart) is denied at the gate through the real stack, before any data is touched.
    smokeCtx()->forget();
    $performStatus = $this->actingAs($u['reception'])
        ->post('/dental/chart/'.$fx['patient']->id.'/perform', ['dental_procedure_id' => 'x', 'branch_id' => 'y'])
        ->status();
    if ($performStatus !== 403) {
        $failures[] = "dental.perform as reception -> {$performStatus} (expected 403)";
    }

    // HOSPITAL.G2: the ADT admit WRITE is admission.manage-gated. Reception (no admission.manage) is
    // denied at the gate through the real stack, before any bed/stay is touched (no 500).
    smokeCtx()->forget();
    $admitStatus = $this->actingAs($u['reception'])
        ->post('/hospital/admissions', ['patient_id' => 'x', 'bed_id' => 'y', 'admitting_clinician_id' => 'z', 'admission_type' => 'elective'])
        ->status();
    if ($admitStatus !== 403) {
        $failures[] = "hospital.admit as reception -> {$admitStatus} (expected 403)";
    }

    // HOSPITAL.G6: the bed-to-billing "invoice this stay" WRITE is billing.manage-gated. Reception (no
    // billing.manage) is denied at the gate through the real stack, before any charge/invoice is touched.
    smokeCtx()->forget();
    $invoiceStatus = $this->actingAs($u['reception'])
        ->post('/hospital/admissions/'.$fx['stay']->id.'/invoice')
        ->status();
    if ($invoiceStatus !== 403) {
        $failures[] = "hospital.admissions.invoice as reception -> {$invoiceStatus} (expected 403)";
    }

    // HOSPITAL.G7: the discharge-summary WRITE is note.write-gated. Reception (patient.view, so it can VIEW
    // the closed episode, but NO note.write) is denied on the author route at the gate through the real stack.
    smokeCtx()->forget();
    $summaryStatus = $this->actingAs($u['reception'])
        ->post('/hospital/admissions/'.$fx['stay']->id.'/discharge-summary', ['summary' => 'x'])
        ->status();
    if ($summaryStatus !== 403) {
        $failures[] = "hospital.discharge-summary.save as reception -> {$summaryStatus} (expected 403)";
    }

    // PHARMACY.G1: the formulary WRITE is formulary.manage-gated. Reception (no formulary.manage) is denied
    // at the gate through the real stack, before any formulary row is touched.
    smokeCtx()->forget();
    $formularyStatus = $this->actingAs($u['reception'])
        ->post('/pharmacy/formulary', ['code' => 'MED-X', 'name' => 'X'])
        ->status();
    if ($formularyStatus !== 403) {
        $failures[] = "pharmacy.formulary.store as reception -> {$formularyStatus} (expected 403)";
    }

    // PHARMACY.G2: prescribing is medication.prescribe-gated. Reception (patient.view, so it can VIEW the
    // med list, but NO medication.prescribe) is denied on the order route at the gate through the real stack.
    smokeCtx()->forget();
    $rxStatus = $this->actingAs($u['reception'])
        ->post('/pharmacy/patients/'.$fx['patient']->id.'/medications', ['formulary_item_id' => 'x', 'dose_amount' => '1', 'dose_unit' => 'mg', 'route' => 'PO', 'frequency' => 'OD'])
        ->status();
    if ($rxStatus !== 403) {
        $failures[] = "pharmacy.patient-medications.store as reception -> {$rxStatus} (expected 403)";
    }

    // PHARMACY.G3: recording an eMAR administration is note.write-gated. Reception (patient.view, so it can
    // VIEW the eMAR, but NO note.write) is denied on the administer route at the gate through the real stack.
    smokeCtx()->forget();
    $adminStatus = $this->actingAs($u['reception'])
        ->post('/pharmacy/medication-orders/x/administer', ['outcome' => 'given'])
        ->status();
    if ($adminStatus !== 403) {
        $failures[] = "pharmacy.medication-orders.administer as reception -> {$adminStatus} (expected 403)";
    }

    // PHARMACY.G4: dispensing is dispense.manage-gated. Reception (patient.view, no dispense.manage) is
    // denied on the dispense route at the gate through the real stack, before any stock is touched.
    smokeCtx()->forget();
    $dispenseStatus = $this->actingAs($u['reception'])
        ->post('/pharmacy/medication-orders/x/dispense', ['quantity' => 1])
        ->status();
    if ($dispenseStatus !== 403) {
        $failures[] = "pharmacy.medication-orders.dispense as reception -> {$dispenseStatus} (expected 403)";
    }

    expect(implode("\n", $failures))->toBe('');
});

test('a portal patient reaches every portal page through the real stack (200)', function () {
    $fx = smokeSeed($this);

    // Use a demo portal account; guarantee its patient holds portal.access so the
    // consent-lockout middleware lets it through.
    $account = PortalAccount::query()->firstOrFail();
    $patient = Patient::query()->findOrFail($account->patient_id);
    if (! app(ConsentService::class)->has($patient, 'portal.access')) {
        ConsentTemplate::query()->firstOrCreate(
            ['key' => 'portal', 'version' => 1],
            ['title' => 'Portal Access', 'body' => 'Portal access consent', 'scope_keys' => ['portal.access'], 'is_active' => true],
        );
        app(ConsentService::class)->grant($patient, 'portal', 'Smoke Patient', $fx['users']['org_admin']);
    }

    $portalRoutes = [
        'home' => '/portal',
        'appointments' => '/portal/appointments',
        'consents' => '/portal/consents',
        'documents' => '/portal/documents',
        'invoices' => '/portal/invoices',
        'messages' => '/portal/messages',
        'telehealth' => '/portal/telehealth',
        'treatment-plan' => '/portal/treatment-plan',
    ];

    $failures = [];
    foreach ($portalRoutes as $label => $url) {
        smokeCtx()->forget();
        $status = $this->actingAs($account, 'patient')
            ->withSession(['portal_tenant_id' => $fx['tenant']->id])
            ->get($url)
            ->status();
        if ($status !== 200) {
            $failures[] = "portal.{$label} [{$url}] -> {$status}";
        }
    }

    expect(implode("\n", $failures))->toBe('');
});
