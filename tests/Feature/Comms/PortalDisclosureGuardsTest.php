<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\IssueService;
use Modules\Billing\Services\PatientBalanceReader;
use Modules\Clinical\Models\Document;
use Modules\Comms\Models\Message;
use Modules\Comms\Models\Thread;
use Modules\Comms\Models\ThreadParticipant;
use Modules\Comms\Services\ThreadService;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientConsent;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * PT.P4 — the three portal disclosure guards, tested the D-182 way.
 *
 * Every refusal below is built so that WITHOUT its guard the fetch would SUCCEED. That is the
 * lesson PT.P3 paid for: an assertion that something is refused proves nothing if the thing was
 * unreachable anyway. So the fixture deliberately contains, and makes otherwise-reachable:
 *
 *   - an UNSHARED document belonging to the portal patient (only `shared_with_patient` hides it);
 *   - a thread the patient does NOT participate in (only the membership check hides it);
 *   - an UN-NUMBERED draft invoice (only `whereNotNull('number')` hides it);
 *   - a live portal session whose `portal.access` consent is then WITHDRAWN (only the service's
 *     consent check refuses the send).
 *
 * Each is paired with a positive control proving the guard is the ONLY thing in the way.
 */

function ptdCtx(): TenantContext
{
    return app(TenantContext::class);
}

/** Sign in the way a real portal request does (the PT.P1 lesson). */
function ptdSignIn($test, PortalAccount $account)
{
    Auth::guard('patient')->setUser($account);

    return $test;
}

/**
 * @return array{tenant: Tenant, staff: User, patient: Patient, control: Patient, account: PortalAccount, shared: Document, unshared: Document, controlDoc: Document, issued: Invoice, draft: Invoice, ownThread: Thread, foreignThread: Thread}
 */
function ptdFixture(): array
{
    $tenant = Tenant::query()->create(['name' => 'Alpha Clinic', 'slug' => 'alpha', 'region' => 'eu', 'status' => 'active']);
    ptdCtx()->set($tenant);

    $staff = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $staff->id,
        'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id,
    ]);
    $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN']);

    $patient = app(PatientService::class)->create([
        'first_name' => 'Erika', 'last_name' => 'Baumgartner', 'date_of_birth' => '1954-03-12', 'sex' => 'female',
    ]);
    $control = app(PatientService::class)->create([
        'first_name' => 'Viktor', 'last_name' => 'Odermatt', 'date_of_birth' => '1968-02-11', 'sex' => 'male',
    ]);

    ConsentTemplate::query()->create([
        'key' => 'portal', 'title' => 'Portal Access', 'body' => 'Portal access consent',
        'version' => 1, 'scope_keys' => ['portal.access'], 'is_active' => true,
    ]);
    app(ConsentService::class)->grant($patient, 'portal', 'Erika Baumgartner', $staff);

    $account = PortalAccount::query()->create([
        'patient_id' => $patient->id,
        'email' => 'erika.guards@example.test',
        'password' => bcrypt('secret-password'),
        'status' => PortalAccount::STATUS_ACTIVE,
    ]);

    $makeDoc = function (Patient $for, string $title, bool $shared) use ($tenant, $staff): Document {
        // Write a real file: without it the download 500s on missing storage and the positive
        // control ("the shared one really does download") would prove nothing.
        Storage::disk('local')->put(
            'tenants/'.$tenant->id.'/documents/'.str($title)->slug().'.pdf',
            '%PDF-1.4 test fixture',
        );

        return Document::query()->create([
            'patient_id' => $for->id, 'category' => Document::CATEGORY_LETTER, 'title' => $title,
            'original_filename' => str($title)->slug().'.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 1024, 'storage_path' => 'tenants/'.$tenant->id.'/documents/'.str($title)->slug().'.pdf',
            'shared_with_patient' => $shared, 'uploaded_by' => $staff->id, 'uploaded_at' => now(),
        ]);
    };

    // THE PATIENT'S OWN documents: one shared, one NOT. The unshared one is the D-182 subject —
    // it belongs to this very patient, so only `shared_with_patient` stands between them.
    $shared = $makeDoc($patient, 'Referral letter', true);
    $unshared = $makeDoc($patient, 'Internal note', false);
    // ...and one that is SHARED but belongs to someone else: only the patient_id clause hides it.
    $controlDoc = $makeDoc($control, 'Control letter', true);

    // Invoices: one ISSUED, one left as a DRAFT (no number) — only whereNotNull('number') hides it.
    $catalog = TariffCatalog::query()->create([
        'key' => 'eu-generic', 'name' => 'EU Generic', 'version' => 1,
        'valid_from' => '2026-01-01', 'status' => TariffCatalog::STATUS_ACTIVE, 'rules' => [],
    ]);
    $item = TariffItem::query()->create([
        'tariff_catalog_id' => $catalog->id, 'code' => '72001', 'description' => 'Consultation',
        'unit_price_minor' => 10000, 'vat_rate_bp' => 0, 'unit' => 'session',
        'requires_service_documentation' => false, 'active' => true,
    ]);
    $charge = fn (int $minor) => Charge::query()->create([
        'patient_id' => $patient->id, 'branch_id' => $branch->id, 'service_date' => now()->toDateString(),
        'tariff_catalog_id' => $catalog->id, 'tariff_item_id' => $item->id, 'code' => $item->code,
        'description' => $item->description, 'unit_price_minor' => $minor, 'vat_rate_bp' => 0,
        'quantity' => 1, 'line_total_minor' => $minor, 'status' => Charge::STATUS_VALIDATED,
        'created_by' => $staff->id,
    ]);

    $issue = app(IssueService::class);
    $issued = $issue->issue(
        $issue->createDraftFromCharges($patient, [$charge(31300)], $staff, Invoice::PAYER_SELF_PAY, null, now(), now()->addDays(30)),
        $staff,
    );
    // Left DRAFT on purpose: it has a real total and belongs to this patient — only the
    // number clause keeps it off the portal.
    $draft = $issue->createDraftFromCharges($patient, [$charge(9900)], $staff, Invoice::PAYER_SELF_PAY, null, now(), now()->addDays(30));

    // Threads: one the patient participates in, and one they do NOT (opened for the control
    // patient, so it is a real, live, patient-type thread — reachable but for the membership check).
    $threads = app(ThreadService::class);
    $ownThread = $threads->openPatientThread($patient, 'Your Thursday appointment', $staff);
    $foreignThread = $threads->openPatientThread($control, 'Someone else conversation', $staff);

    // A staff reply that was AI-ASSISTED — sent by the human, drafted with help.
    $threads->postStaffMessage($ownThread, $staff, 'We can offer Thursday 15:00 or 15:45.', true);

    return compact('tenant', 'staff', 'patient', 'control', 'account', 'shared', 'unshared', 'controlDoc', 'issued', 'draft', 'ownThread', 'foreignThread');
}

/**
 * Strip comments so the scans test AFFORDANCES, not the prose documenting their absence.
 *
 * `portal.messages.urgentNote` is stripped for the same reason (the PC.P6 lesson): it is the
 * wireframe's own SAFETY FOOTNOTE — "for anything urgent please call the practice — this inbox
 * is checked during opening hours" — a static instruction telling the patient what this channel
 * is NOT for. It is the opposite of the system judging a message's urgency, and it must not be
 * what fails the test banning urgency. Its text is asserted verbatim below, so nothing hides.
 */
function ptdStrip(string $source): string
{
    $source = preg_replace('~/\*.*?\*/~s', ' ', $source) ?? $source;
    $source = preg_replace('~<!--.*?-->~s', ' ', $source) ?? $source;
    $source = preg_replace("~t\('portal\.messages\.urgentNote'\)~", ' ', $source) ?? $source;

    return strtolower(preg_replace('~(^|\s)//[^\n]*~m', '$1 ', $source) ?? $source);
}

test('GUARD 1 — only shared documents are visible, and the unshared one is REFUSED not merely absent', function () {
    $fx = ptdFixture();

    ptdCtx()->forget();
    $props = ptdSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->get(route('portal.documents.index'))
        ->assertOk()
        ->viewData('page')['props'];

    // Only the shared one is listed.
    expect($props['documents'])->toHaveCount(1)
        ->and($props['documents'][0]['title'])->toBe('Referral letter');

    /*
     * POSITIVE CONTROL (D-182): the unshared document is THIS PATIENT'S OWN and exists right now —
     * so the only thing keeping it out of their hands is `shared_with_patient`. Remove that clause
     * and this fetch would succeed, which is exactly what makes the refusal meaningful.
     */
    ptdCtx()->set($fx['tenant']);
    expect(Document::query()->whereKey($fx['unshared']->id)->where('patient_id', $fx['patient']->id)->exists())->toBeTrue();

    ptdCtx()->forget();
    ptdSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->get(route('portal.documents.show', $fx['unshared']->id))
        ->assertNotFound();

    // A SHARED document belonging to someone else is equally out of reach.
    ptdCtx()->forget();
    ptdSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->get(route('portal.documents.show', $fx['controlDoc']->id))
        ->assertNotFound();

    // ...and the shared one really does download, so the refusals above are not a broken route.
    ptdCtx()->forget();
    ptdSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->get(route('portal.documents.show', $fx['shared']->id))
        ->assertOk();
});

test('GUARD 2 — the portal lists ISSUED invoices only, and the balance is the reader (δ=0)', function () {
    $fx = ptdFixture();

    ptdCtx()->set($fx['tenant']);
    $engine = app(PatientBalanceReader::class)->outstandingMinorFor($fx['patient']->id);

    /*
     * POSITIVE CONTROL (D-182): the draft invoice is real, belongs to this patient and carries a
     * total — only `whereNotNull('number')` keeps it off the screen. Without that clause it would
     * appear, so this exclusion is a guard rather than an accident of the fixture.
     */
    expect($fx['draft']->number)->toBeNull()
        ->and($fx['draft']->patient_id)->toBe($fx['patient']->id)
        ->and((int) $fx['draft']->total_minor)->toBeGreaterThan(0);

    ptdCtx()->forget();
    $props = ptdSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->get(route('portal.invoices'))
        ->assertOk()
        ->viewData('page')['props'];

    $ids = collect($props['invoices'])->pluck('id')->all();
    expect($ids)->toContain($fx['issued']->id)
        ->and($ids)->not->toContain($fx['draft']->id);

    // The balance is PT.P2's one source, tying to the engine.
    expect($props['outstanding']['minor'])->toBe($engine);

    // The draft's PDF is unreachable too — the list is not the only thing scoping this.
    ptdCtx()->forget();
    ptdSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->get(route('portal.invoices.download', $fx['draft']->id))
        ->assertNotFound();
});

test('GUARD 3 — a thread the patient does not participate in is REFUSED, not merely unlisted', function () {
    $fx = ptdFixture();

    ptdCtx()->forget();
    $props = ptdSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->get(route('portal.messages'))
        ->assertOk()
        ->viewData('page')['props'];

    $threadIds = collect($props['threads'])->pluck('id')->all();
    expect($threadIds)->toContain($fx['ownThread']->id)
        ->and($threadIds)->not->toContain($fx['foreignThread']->id);

    /*
     * POSITIVE CONTROL (D-182): the foreign thread is a real, OPEN, patient-type thread that exists
     * right now — only the ThreadParticipant membership check stands in the way. Asking for it by
     * id must be refused rather than quietly served.
     */
    ptdCtx()->set($fx['tenant']);
    expect(Thread::query()->whereKey($fx['foreignThread']->id)->exists())->toBeTrue()
        ->and($fx['foreignThread']->status)->toBe(Thread::STATUS_OPEN);

    ptdCtx()->forget();
    ptdSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->get(route('portal.messages', ['thread_id' => $fx['foreignThread']->id]))
        ->assertForbidden();

    // Posting into it is refused too — reading and writing are guarded by the same check.
    ptdCtx()->forget();
    ptdSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->post(route('portal.messages.store'), ['thread_id' => $fx['foreignThread']->id, 'body' => 'Forged.'])
        ->assertForbidden();

    ptdCtx()->set($fx['tenant']);
    expect(Message::query()->where('thread_id', $fx['foreignThread']->id)->count())->toBe(0);

    /*
     * ...AND THE MEMBERSHIP CHECK IS ISOLATED (D-182, the subtle case).
     *
     * The foreign thread above belongs to ANOTHER patient, so `assertPatientAccess()` refuses it
     * on the earlier patient_id comparison — the ThreadParticipant check is never the deciding
     * factor, and a mutation deleting it passed this test. To pin the membership check itself
     * the thread must be the patient's OWN, with their participation REMOVED: then membership
     * is the only thing left standing between them and the conversation.
     */
    ptdCtx()->set($fx['tenant']);
    ThreadParticipant::query()
        ->where('thread_id', $fx['ownThread']->id)
        ->where('patient_id', $fx['patient']->id)
        ->update(['removed_at' => now()]);

    // POSITIVE CONTROL: it is still THEIR thread — only the participation row changed.
    expect(Thread::query()->whereKey($fx['ownThread']->id)->value('patient_id'))->toBe($fx['patient']->id);

    ptdCtx()->forget();
    ptdSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->get(route('portal.messages', ['thread_id' => $fx['ownThread']->id]))
        ->assertForbidden();
});

test('GUARD 4 — withdrawing portal consent refuses sending SERVER-side, even on a forged post', function () {
    $fx = ptdFixture();

    // POSITIVE CONTROL: with consent in place the very same post SUCCEEDS. Without this the
    // refusal below could be caused by anything at all.
    ptdCtx()->forget();
    ptdSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->post(route('portal.messages.store'), ['thread_id' => $fx['ownThread']->id, 'body' => 'Yes, 15:00 works.'])
        ->assertRedirect();

    ptdCtx()->set($fx['tenant']);
    $sent = Message::query()->where('thread_id', $fx['ownThread']->id)
        ->where('author_type', Message::AUTHOR_PATIENT)->count();
    expect($sent)->toBe(1);

    // Now WITHDRAW portal.access — the consent the service itself re-checks on every send.
    PatientConsent::query()
        ->where('patient_id', $fx['patient']->id)
        ->update(['status' => PatientConsent::STATUS_WITHDRAWN, 'withdrawn_at' => now()]);

    ptdCtx()->forget();
    ptdSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->post(route('portal.messages.store'), ['thread_id' => $fx['ownThread']->id, 'body' => 'Should never land.'])
        ->assertForbidden();

    ptdCtx()->set($fx['tenant']);
    expect(Message::query()->where('thread_id', $fx['ownThread']->id)
        ->where('author_type', Message::AUTHOR_PATIENT)->count())->toBe($sent);

    /*
     * ...AND THE SERVICE RE-CHECKS IT ITSELF (D-182, third variant).
     *
     * Over HTTP the `portal-consent` middleware refuses first, so deleting the service's own
     * consent check changes nothing about the response — a mutation removing it passed the test
     * above. Defence in depth is only real if each layer is separately pinned, so call the
     * service DIRECTLY: with consent withdrawn it must refuse on its own, without the middleware
     * in front of it.
     */
    expect(fn () => app(ThreadService::class)->postPatientMessage(
        $fx['ownThread']->refresh(),
        $fx['patient']->refresh(),
        'Straight at the service.',
    ))->toThrow(AuthorizationException::class);

    ptdCtx()->set($fx['tenant']);
    expect(Message::query()->where('thread_id', $fx['ownThread']->id)
        ->where('author_type', Message::AUTHOR_PATIENT)->count())->toBe($sent);
});

test('agent provenance is shown as RECORDED — and nothing was auto-sent', function () {
    $fx = ptdFixture();

    ptdCtx()->forget();
    $props = ptdSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->get(route('portal.messages', ['thread_id' => $fx['ownThread']->id]))
        ->assertOk()
        ->viewData('page')['props'];

    $staffMessage = collect($props['activeThread']['messages'])->firstWhere('author_type', Message::AUTHOR_STAFF);
    expect($staffMessage)->not->toBeNull()
        ->and($staffMessage['ai_assisted'])->toBeTrue();

    /*
     * THE CEILING IS WHAT MAKES THIS SAFE (the PC.P7 rule). `ai_assisted` does not mean an agent
     * messaged the patient: `DraftReplyTool` is capped at SUGGEST and NEVER posts — the row exists
     * only because a staff member explicitly sent it, with the human as actor. Assert the tool's
     * contract so a future change to that ceiling cannot pass quietly.
     */
    $tool = (string) file_get_contents(base_path('app/AiCore/Tools/DraftReplyTool.php'));
    expect($tool)->toContain('autonomyCeiling: AutonomyPolicy::SUGGEST')
        ->and($tool)->toContain('The agent NEVER posts');

    // The message is authored by the STAFF user, not by a service or an agent identity.
    ptdCtx()->set($fx['tenant']);
    $row = Message::query()->where('thread_id', $fx['ownThread']->id)
        ->where('author_type', Message::AUTHOR_STAFF)->firstOrFail();
    expect($row->author_staff_user_id)->toBe($fx['staff']->id);

    // The portal offers the PATIENT no AI affordance of any kind.
    $page = ptdStrip((string) file_get_contents(base_path('resources/js/pages/Portal/Messages.vue')));
    foreach (['draftreply', 'aicore', 'suggestreply', 'autoreply', 'askai', 'generate'] as $affordance) {
        expect(str_contains($page, $affordance))->toBeFalse("the portal offers the patient an AI affordance: '{$affordance}'");
    }
});

test('THE FENCE: no interpretation, no urgency, and nothing tinted by age or severity', function () {
    $fx = ptdFixture();

    ptdCtx()->forget();
    $invoices = ptdSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->get(route('portal.invoices'))
        ->assertOk()
        ->viewData('page')['props'];

    // POSITIVE CONTROL: a non-empty payload with a real open balance to editorialise about.
    expect($invoices['invoices'])->not->toBeEmpty()
        ->and($invoices['outstanding']['minor'])->toBeGreaterThan(0);

    $forbidden = [
        'interpretation', 'abnormal', 'urgent', 'urgency', 'riskscore', 'risklevel', 'paymentrisk',
        'creditrisk', 'severity', 'overdueband', 'daysoverdue', 'dunninglevel', 'collections',
    ];

    $squashed = preg_replace('~[^a-z0-9]~', '', strtolower(json_encode($invoices) ?: '')) ?? '';
    // A modest floor: the real control above is that the payload holds invoices AND a non-zero
    // balance — something to editorialise about — not that it is long.
    expect(strlen($squashed))->toBeGreaterThan(200);
    foreach ($forbidden as $token) {
        expect(str_contains($squashed, $token))->toBeFalse("fence token '{$token}' appears in the invoices payload");
    }

    /*
     * The safety footnote must STAY. It is the one place "urgent" belongs on a patient-facing
     * screen: telling them this inbox is not the channel for it.
     */
    $en = json_decode((string) file_get_contents(base_path('resources/js/lang/en.json')), true);
    expect($en['portal']['messages']['urgentNote'])->toContain('call the practice');

    // D-173 — the scan follows every portal surface this gate touched.
    foreach ([
        base_path('resources/js/pages/Portal/Documents.vue'),
        base_path('resources/js/pages/Portal/Invoices.vue'),
        base_path('resources/js/pages/Portal/Messages.vue'),
    ] as $path) {
        expect(file_exists($path))->toBeTrue(basename($path).' is missing — this fence would scan nothing');
        $code = ptdStrip((string) file_get_contents($path));
        $squashedFile = preg_replace('~[^a-z0-9]~', '', $code) ?? '';

        foreach ($forbidden as $token) {
            expect(str_contains($squashedFile, $token))->toBeFalse("fence token '{$token}' appears in ".basename($path));
        }

        // D-179 — no pay affordance anywhere: no payment path exists, so none may be implied.
        foreach (['paynow', 'payinvoice', 'stripe', 'checkout'] as $payment) {
            expect(str_contains($squashedFile, $payment))->toBeFalse(basename($path)." implies a payment CareOS cannot take: '{$payment}'");
        }

        /*
         * D-169 — nothing may be styled by an invoice's AGE or a message's importance. Status
         * SELECTION (which filter chip is active) stays permitted, exactly as PT.P3 established for
         * slot selection: the line is between identity/selection and a judgment about the value.
         */
        preg_match_all('~:(?:class|style)="([^"]*)"~', $code, $bindings);
        foreach ($bindings[1] ?? [] as $binding) {
            foreach (['due_date', 'duedate', 'overdue', 'urgency', 'severity', 'risk'] as $needle) {
                expect(str_contains($binding, $needle))->toBeFalse(basename($path)." styles by age or severity: {$binding}");
            }
            expect(preg_match('~\bnow\(\)~', $binding))->toBe(0, basename($path).' styles against the clock');
        }
    }
});

test('PT.P1 audit rows are unchanged — one per render on all three screens', function () {
    $fx = ptdFixture();

    $surfaces = [
        'portal_documents' => route('portal.documents.index'),
        'portal_invoices' => route('portal.invoices'),
    ];

    foreach ($surfaces as $surface => $url) {
        ptdCtx()->forget();
        ptdSignIn($this, $fx['account'])
            ->withSession(['portal_tenant_id' => $fx['tenant']->id])
            ->get($url)
            ->assertOk();

        ptdCtx()->set($fx['tenant']);

        // Decoded, never byte-matched (the PT.P1-FIX lesson).
        $rows = DB::table('audit_events')
            ->where('action', 'read')
            ->where('patient_id', $fx['patient']->id)
            ->pluck('context')
            ->filter(function ($context) use ($surface): bool {
                $decoded = json_decode((string) $context, true);

                return is_array($decoded) && ($decoded['surface'] ?? null) === $surface;
            });

        expect($rows)->toHaveCount(1, "{$surface} no longer writes exactly one row per render");
    }
});
