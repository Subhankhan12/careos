<?php

use Database\Seeders\DemoClinicSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Clinical\Models\ClinicalNote;
use Modules\Clinical\Models\Encounter;
use Modules\Import\Models\ImportBatch;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Services\ConsentService;
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

    return compact('tenant', 'users', 'invoice', 'creditNote', 'payment', 'patient', 'encounter', 'note', 'batch');
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
        'reporting' => '/reporting',
        'imports.index' => '/imports',
        'imports.create' => '/imports/create',
        'admin.kiosks' => '/admin/kiosks',
        // Detail pages (real ids) — the billing/import ones are the C-1 regression surface.
        'patient.show' => '/patients/'.$fx['patient']->id,
        'chart' => '/clinical/chart/'.$fx['patient']->id,
        'encounter.show' => '/clinical/encounters/'.$fx['encounter']->id,
        'note.show' => '/clinical/notes/'.$fx['note']->id,
        'note.edit' => '/clinical/notes/'.$fx['note']->id.'/edit',
        'invoice.show (C-1)' => '/billing/invoices/'.$fx['invoice']->id,
        'invoice.pdf' => '/billing/invoices/'.$fx['invoice']->id.'/pdf',
        'credit-note.show (C-1)' => '/billing/credit-notes/'.$fx['creditNote']->id,
        'payment.show (C-1)' => '/billing/payments/'.$fx['payment']->id,
        'import.show (C-1)' => '/imports/'.$fx['batch']->id,
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
