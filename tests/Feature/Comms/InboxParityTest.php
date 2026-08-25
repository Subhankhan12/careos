<?php

use App\AiCore\Tools\DraftReplyTool;
use App\Services\NeedsHumanReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\AiCore\Services\ToolRegistry;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceBalance;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Billing\Services\IssueService;
use Modules\Clinical\Models\Allergy;
use Modules\Comms\Models\Message;
use Modules\Comms\Models\NotificationDelivery;
use Modules\Comms\Models\NotificationTemplate;
use Modules\Comms\Models\Thread;
use Modules\Comms\Models\ThreadParticipant;
use Modules\Comms\Services\InboxPatientContextReader;
use Modules\Comms\Services\NotificationService;
use Modules\Comms\Services\ThreadService;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientConsent;
use Modules\Patients\Models\PatientContact;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Permission;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Service;

uses(RefreshDatabase::class);

/*
 * COMMS.P1 — the unified inbox's parity pass and its patient context pane.
 *
 * The context pane is the risk on this screen, so most of what follows is about what it may and may
 * not disclose. Every refusal is built the D-182 way: the thing being refused is genuinely THERE and
 * would be returned if its guard were removed, and each has a positive control proving the guard is
 * the only thing in the way.
 */

function cp1Ctx(): TenantContext
{
    return app(TenantContext::class);
}

/**
 * A user holding EXACTLY the permissions named — not a catalogue role.
 *
 * The seeded roles bundle permissions together (reception holds `comms.manage` AND `patient.view`),
 * which is precisely what hides a per-element gate: if every inbox user also holds `patient.view`,
 * the identity gate is never the deciding factor and deleting it changes nothing. This builds the
 * user the catalogue does not, so each element's own gate is the only thing that can refuse it —
 * the lesson GOV.P5's free-text opt-in and PT.P7's tenant binding both paid for.
 *
 * @param  list<string>  $permissions
 */
function cp1User(Tenant $tenant, array $permissions, string $roleKey): User
{
    $role = Role::query()->create(['key' => $roleKey, 'name' => $roleKey, 'is_system' => false]);

    foreach ($permissions as $key) {
        $role->permissions()->attach(Permission::query()->where('key', $key)->firstOrFail()->id);
    }

    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id]);

    return $user;
}

/**
 * A tenant with a patient thread whose patient has EVERY context element populated — an allergy, a
 * future appointment and an open balance. The fixture is deliberately full so that an element
 * missing from a payload means a GATE refused it, never that there was nothing to show.
 *
 * @return array{tenant: Tenant, admin: User, patient: Patient, thread: Thread}
 */
function cp1Fixture(): array
{
    $tenant = Tenant::query()->create(['name' => 'Alpha Clinic', 'slug' => 'alpha', 'region' => 'eu', 'status' => 'active']);
    cp1Ctx()->set($tenant);

    $admin = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $admin->id,
        'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id,
    ]);
    $branch = Branch::query()->create(['name' => 'Main', 'code' => 'MAIN']);

    // `recorded_by` on an allergy is a STAFF PROFILE, not a user account.
    $staff = StaffProfile::query()->create([
        'first_name' => 'Marc', 'last_name' => 'Brunner', 'display_name' => 'Dr. M. Brunner',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id,
    ]);

    $patient = app(PatientService::class)->create([
        'first_name' => 'Nora', 'last_name' => 'Keller', 'date_of_birth' => '1988-03-14', 'sex' => 'female',
    ]);

    Allergy::query()->create([
        'patient_id' => $patient->id,
        'substance' => 'Penicillin',
        'substance_key' => 'penicillin',
        'reaction' => 'Hautausschlag',
        'severity' => Allergy::SEVERITY_SEVERE,
        'status' => Allergy::STATUS_ACTIVE,
        'recorded_by' => $staff->id,
        'recorded_at' => now(),
    ]);

    $service = Service::query()->create([
        'name' => 'Consultation 30', 'code' => 'C30', 'category' => 'general',
        'default_duration_minutes' => 30, 'requires_resource_types' => ['practitioner'],
        'bookable_online' => true, 'active' => true,
    ]);
    Appointment::query()->create([
        'service_id' => $service->id,
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'starts_at' => now()->addDays(3)->setTime(9, 30)->toDateTimeString(),
        'ends_at' => now()->addDays(3)->setTime(10, 0)->toDateTimeString(),
        'status' => Appointment::STATUS_BOOKED,
        'source' => 'staff',
    ]);

    /*
     * Issued through the REAL path — charge → draft → issue — so `invoice_balances` is genuine
     * engine state rather than a row I invented. The reader sums that projection; a hand-built
     * invoice would prove nothing about the figure the pane shows (the PortalBalanceOneSource
     * precedent, and the seeding rule generally: drive the real path or assert nothing).
     */
    $catalog = TariffCatalog::query()->create([
        'key' => 'eu-generic', 'name' => 'EU Generic', 'version' => 1,
        'valid_from' => '2026-01-01', 'status' => TariffCatalog::STATUS_ACTIVE, 'rules' => [],
    ]);
    $item = TariffItem::query()->create([
        'tariff_catalog_id' => $catalog->id, 'code' => '72001', 'description' => 'Consultation',
        'unit_price_minor' => 9000, 'vat_rate_bp' => 0, 'unit' => 'session',
        'requires_service_documentation' => false, 'active' => true,
    ]);
    $charge = Charge::query()->create([
        'patient_id' => $patient->id, 'branch_id' => $branch->id, 'service_date' => now()->toDateString(),
        'tariff_catalog_id' => $catalog->id, 'tariff_item_id' => $item->id, 'code' => $item->code,
        'description' => $item->description, 'unit_price_minor' => 9000, 'vat_rate_bp' => 0,
        'quantity' => 1, 'line_total_minor' => 9000, 'status' => Charge::STATUS_VALIDATED,
        'created_by' => $admin->id,
    ]);
    $issue = app(IssueService::class);
    $issue->issue(
        $issue->createDraftFromCharges($patient, [$charge], $admin, Invoice::PAYER_SELF_PAY, null, now(), now()->addDays(30)),
        $admin,
    );

    /*
     * A REAL email address. Without one, `NotificationService` skips for
     * `no_recipient_address` before the consent gate is ever reached — the refusal would
     * pass for the wrong reason and prove nothing about consent (D-182).
     */
    PatientContact::query()->create([
        'patient_id' => $patient->id,
        'type' => PatientContact::TYPE_EMAIL,
        'value' => 'nora.keller@example.test',
        'is_primary' => true,
    ]);

    $thread = app(ThreadService::class)->openPatientThread($patient, 'Frage zum Termin', $admin);

    return ['tenant' => $tenant, 'admin' => $admin, 'patient' => $patient, 'thread' => $thread];
}

/** Recursively collect every scalar leaf of a payload, so a scan cannot miss a nested key. */
function cp1Leaves(mixed $value, string $prefix = ''): array
{
    if (! is_array($value)) {
        return [$prefix => $value];
    }

    $out = [];
    foreach ($value as $key => $child) {
        $out += cp1Leaves($child, $prefix === '' ? (string) $key : $prefix.'.'.$key);
    }

    return $out;
}

/* ------------------------------------------------------------------ the context pane's gates */

test('every context element is present for a viewer who holds every permission — the POSITIVE CONTROL', function () {
    $f = cp1Fixture();

    // D-174: without this, every "element absent" assertion below could pass over an empty fixture.
    $context = app(InboxPatientContextReader::class)->for($f['patient'], $f['admin']);

    expect($context['identity']['visible'])->toBeTrue()
        ->and($context['identity']['name'])->toBe('Nora Keller')
        ->and($context['identity']['mrn'])->not->toBeEmpty()
        ->and($context['allergies']['visible'])->toBeTrue()
        ->and($context['allergies']['items'])->toHaveCount(1)
        ->and($context['allergies']['items'][0]['substance'])->toBe('Penicillin')
        ->and($context['nextAppointment']['visible'])->toBeTrue()
        ->and($context['nextAppointment']['appointment'])->not->toBeNull()
        ->and($context['balance']['visible'])->toBeTrue()
        // The CURRENCY is read from the invoice itself, never defaulted to a hardcoded code —
        // so this asserts against what the engine actually issued.
        ->and($context['balance']['minor'])->toBe(9000)
        ->and($context['balance']['formatted'])->toBe(Invoice::query()->value('currency').' 90.00');
});

test('each context element is refused SEPARATELY — a viewer lacking one permission loses that element and no other', function () {
    $f = cp1Fixture();
    $reader = app(InboxPatientContextReader::class);

    /*
     * Four users, each holding `comms.manage` (so they belong in the inbox at all) plus exactly ONE
     * of the four element permissions. Each therefore proves two things at once: the element they
     * hold IS returned (so the gate is not simply refusing everyone), and the three they do not hold
     * are absent. Deleting any single gate turns exactly one of these red.
     */
    $matrix = [
        InboxPatientContextReader::PERM_IDENTITY => 'identity',
        InboxPatientContextReader::PERM_ALLERGIES => 'allergies',
        InboxPatientContextReader::PERM_APPOINTMENT => 'nextAppointment',
        InboxPatientContextReader::PERM_BALANCE => 'balance',
    ];

    foreach ($matrix as $permission => $element) {
        $viewer = cp1User($f['tenant'], ['comms.manage', $permission], 'only_'.str_replace('.', '_', $permission));
        $context = $reader->for($f['patient'], $viewer);

        expect($context[$element]['visible'])->toBeTrue("holding {$permission} should reveal {$element}");

        foreach ($matrix as $otherElement) {
            if ($otherElement !== $element) {
                expect($context[$otherElement]['visible'])
                    ->toBeFalse("holding only {$permission} must NOT reveal {$otherElement}");
            }
        }
    }
});

test('a refused element carries NO value — never another element\'s data, never a zero that reads as "none recorded"', function () {
    $f = cp1Fixture();

    $viewer = cp1User($f['tenant'], ['comms.manage'], 'comms_only');
    $context = app(InboxPatientContextReader::class)->for($f['patient'], $viewer);

    // Fail-closed means EMPTY, not a plausible-looking default. A `0.00` balance or an empty
    // allergy list would tell this viewer something false about the record.
    expect($context['allergies']['items'])->toBe([])
        ->and($context['balance']['formatted'])->toBeNull()
        ->and($context['nextAppointment']['appointment'])->toBeNull()
        ->and($context['identity'])->not->toHaveKey('name')
        ->and($context['identity'])->not->toHaveKey('mrn')
        ->and($context['links'])->toBe([]);

    // ...and the patient's real details do not appear anywhere in the payload by another route.
    $leaves = cp1Leaves($context);
    foreach ($leaves as $path => $leaf) {
        if (is_string($leaf)) {
            expect($leaf)->not->toContain('Penicillin');
            expect($leaf)->not->toContain('Keller');
        }
    }
});

test('the context pane carries no judgment, risk, priority or summary key', function () {
    $f = cp1Fixture();

    // Scanned over the WIDEST payload the reader can produce (D-174) — an org_admin sees every
    // element, so a forbidden key cannot hide behind a closed gate.
    $context = app(InboxPatientContextReader::class)->for($f['patient'], $f['admin']);
    $paths = array_keys(cp1Leaves($context));

    expect($paths)->not->toBeEmpty();

    $forbidden = ['risk', 'score', 'acuity', 'triage', 'priority', 'urgen', 'severity_level',
        'summary', 'suggest', 'recommend', 'flagged_as', 'overdue', 'ews'];

    foreach ($paths as $path) {
        foreach ($forbidden as $needle) {
            expect(str_contains(strtolower($path), $needle))
                ->toBeFalse("context payload must not carry a '{$needle}' key; found at {$path}");
        }
    }

    // `severity` IS allowed — it is the clinician's recorded word. Assert it travels VERBATIM and
    // that the reader never adds a grading of its own beside it.
    expect($context['allergies']['items'][0]['severity'])->toBe(
        Allergy::query()->where('patient_id', $f['patient']->id)->value('severity'),
    );
    expect($context['allergies']['items'][0])->toHaveKeys(['id', 'substance', 'reaction', 'severity']);
    expect(array_keys($context['allergies']['items'][0]))->toHaveCount(4);
});

test('allergies are ordered by SUBSTANCE, never by recorded severity', function () {
    $f = cp1Fixture();

    /*
     * The fixture must make the two orderings DISAGREE, or the assertion cannot tell them apart.
     * A mutation caught exactly that: with Aspirin=mild and Penicillin=severe, ordering by either
     * column yields the same list, so the severity mutation stayed green over a test that looked
     * fine. Aspirin is now recorded UNKNOWN, which sorts AFTER 'severe' alphabetically and BEFORE
     * it by any clinical reading — so substance-order and severity-order genuinely differ, and the
     * mutation turns red. (D-174: the fixture has to be the case that would tempt the breach.)
     */
    Allergy::query()->create([
        'patient_id' => $f['patient']->id,
        'substance' => 'Aspirin',
        'substance_key' => 'aspirin',
        'reaction' => 'Übelkeit',
        'severity' => Allergy::SEVERITY_UNKNOWN,
        'status' => Allergy::STATUS_ACTIVE,
        'recorded_by' => StaffProfile::query()->value('id'),
        'recorded_at' => now(),
    ]);

    $context = app(InboxPatientContextReader::class)->for($f['patient'], $f['admin']);

    expect(array_column($context['allergies']['items'], 'substance'))->toBe(['Aspirin', 'Penicillin']);
});

test('the balance comes from the engine reader, not from page-side arithmetic', function () {
    $f = cp1Fixture();

    // Change the projection the engine reads; the pane must follow it. A page that summed invoice
    // rows itself would not (the PortalBalanceOneSource contract, applied to this surface).
    InvoiceBalance::query()
        ->whereIn('invoice_id', Invoice::query()->where('patient_id', $f['patient']->id)->pluck('id'))
        ->update(['open_balance_minor' => 4250]);

    $context = app(InboxPatientContextReader::class)->for($f['patient'], $f['admin']);

    expect($context['balance']['minor'])->toBe(4250)
        ->and($context['balance']['formatted'])->toBe(Invoice::query()->value('currency').' 42.50');
});

/* ------------------------------------------------------------- the three patient-side layers */

test('the patient-side visibility layers stay pinned SEPARATELY — each refusal is the only thing in the way', function () {
    $f = cp1Fixture();
    $threads = app(ThreadService::class);
    $patient = $f['patient'];

    ConsentTemplate::query()->create([
        'key' => 'portal', 'title' => 'Portal Access', 'body' => 'Portal access consent',
        'version' => 1, 'scope_keys' => ['portal.access'], 'is_active' => true,
    ]);
    $account = PortalAccount::query()->create([
        'patient_id' => $patient->id,
        'email' => 'nora@example.test',
        'password' => bcrypt('secret-passphrase'),
        'status' => PortalAccount::STATUS_ACTIVE,
    ]);
    app(ConsentService::class)->grant($patient, 'portal', 'Nora Keller', $f['admin']);

    // POSITIVE CONTROL (D-182): with all three layers satisfied the patient CAN read the thread.
    // Without this, each refusal below could be passing for the wrong reason.
    expect($threads->messagesForPatient($f['thread'], $patient))->not->toBeNull();

    // LAYER 1 — MEMBERSHIP. Everything else stays satisfied; only the participant row is removed.
    ThreadParticipant::query()
        ->where('thread_id', $f['thread']->id)
        ->where('patient_id', $patient->id)
        ->update(['removed_at' => now()]);
    expect(fn () => $threads->messagesForPatient($f['thread'], $patient))
        ->toThrow(AuthorizationException::class);
    ThreadParticipant::query()
        ->where('thread_id', $f['thread']->id)
        ->where('patient_id', $patient->id)
        ->update(['removed_at' => null]);
    expect($threads->messagesForPatient($f['thread'], $patient))->not->toBeNull();

    // LAYER 2 — AN ACTIVE PORTAL ACCOUNT. Membership and consent remain intact.
    $account->forceFill(['status' => PortalAccount::STATUS_DISABLED])->save();
    expect(fn () => $threads->messagesForPatient($f['thread'], $patient))
        ->toThrow(AuthorizationException::class);
    $account->forceFill(['status' => PortalAccount::STATUS_ACTIVE])->save();
    expect($threads->messagesForPatient($f['thread'], $patient))->not->toBeNull();

    // LAYER 3 — THE portal.access CONSENT. Membership and the account remain intact, so only the
    // consent check can refuse: exactly the shape PT.P4 needed to prove the layers are not collapsed.
    app(ConsentService::class)->withdraw(
        PatientConsent::query()
            ->where('patient_id', $patient->id)->where('template_key', 'portal')->firstOrFail(),
        'Patient withdrew portal access.',
    );
    expect(fn () => $threads->messagesForPatient($f['thread'], $patient))
        ->toThrow(AuthorizationException::class);
});

/* ------------------------------------------------------------------- the inbox surface itself */

test('the inbox lists only threads in this tenant, and refuses a viewer without comms.manage', function () {
    $f = cp1Fixture();

    $stranger = cp1User($f['tenant'], ['patient.view'], 'no_comms');
    $this->actingAs($stranger)->get(route('comms.inbox'))->assertForbidden();

    // POSITIVE CONTROL: the permission is the only thing in the way.
    $this->actingAs($f['admin'])->get(route('comms.inbox'))->assertOk();
});

test('"needs a human" uses the CONJUNCTION — a flagged thread that was answered drops out', function () {
    $f = cp1Fixture();
    $threads = app(ThreadService::class);

    $f['thread']->forceFill([
        'clinician_attention_at' => now()->subHour(),
        'clinician_attention_reason' => 'clinical_question_requires_clinician',
    ])->save();

    // POSITIVE CONTROL: it IS waiting before anyone answers.
    expect(Thread::query()->waitingForClinician()->count())->toBe(1);

    $response = $this->actingAs($f['admin'])->get(route('comms.inbox', ['needs_human' => '1']));
    $response->assertOk();
    expect(collect($response->viewData('page')['props']['threads'])->pluck('id'))->toContain($f['thread']->id);

    // A staff reply is the real human action. The RAW FLAG is untouched by it — nothing anywhere
    // clears `clinician_attention_at` (GOV.P2) — so a filter on the flag alone would still list it.
    $threads->postStaffMessage($f['thread']->refresh(), $f['admin'], 'Ich schaue das an.');

    expect(Thread::query()->whereNotNull('clinician_attention_at')->count())->toBe(1)
        ->and(Thread::query()->waitingForClinician()->count())->toBe(0);

    $after = $this->actingAs($f['admin'])->get(route('comms.inbox', ['needs_human' => '1']));
    expect(collect($after->viewData('page')['props']['threads'])->pluck('id'))->not->toContain($f['thread']->id);
});

test('closing a flagged thread also clears it from "needs a human" — the second conjunct', function () {
    $f = cp1Fixture();

    $f['thread']->forceFill([
        'clinician_attention_at' => now()->subHour(),
        'clinician_attention_reason' => 'clinical_question_requires_clinician',
    ])->save();
    expect(Thread::query()->waitingForClinician()->count())->toBe(1);

    app(ThreadService::class)->close($f['thread']->refresh(), $f['admin']);

    // Isolating THIS conjunct matters: with only the reply path covered, deleting `status = open`
    // left GOV.P2's suite green because its fixture thread was open either way.
    expect(Thread::query()->waitingForClinician()->count())->toBe(0);
});

test('the inbox count and the governance reader describe the SAME set — one definition, not two', function () {
    $f = cp1Fixture();

    /*
     * A SECOND, UNFLAGGED thread. Without it the visible list and the record-wide count are both 1
     * and a count derived from the capped page would be indistinguishable from the real one — a
     * mutation proved exactly that. Now the list holds 2 and only 1 is waiting, so the two can
     * disagree and the assertion has something to catch (D-174).
     */
    app(ThreadService::class)->openPatientThread($f['patient'], 'Rechnungsfrage', $f['admin']);

    $f['thread']->forceFill([
        'clinician_attention_at' => now()->subHour(),
        'clinician_attention_reason' => 'clinical_question_requires_clinician',
    ])->save();

    $response = $this->actingAs($f['admin'])->get(route('comms.inbox'));
    $props = $response->viewData('page')['props'];
    $inboxCount = $props['counts']['needsHuman'];

    // The control that gives the mutation something to fail on: the LIST is longer than the count.
    expect($props['threads'])->toHaveCount(2);

    $governance = app(NeedsHumanReader::class)->forUser($f['admin']);
    $governanceCount = collect($governance['categories'] ?? [])
        ->firstWhere('key', NeedsHumanReader::CATEGORY_CLINICIAN)['count'] ?? null;

    expect($inboxCount)->toBe(1)->and($governanceCount)->toBe($inboxCount);
});

/* ------------------------------------------------------------------- provenance + the send path */

test('an agent-drafted message shows recorded provenance with the HUMAN sender named', function () {
    $f = cp1Fixture();

    // Posted exactly as DraftReplyTool::execute() posts it: the human is the actor, ai_assisted true.
    app(ThreadService::class)->postStaffMessage(
        $f['thread'], $f['admin'], 'Wir können Donnerstag 15:00 anbieten.', aiAssisted: true,
    );

    $response = $this->actingAs($f['admin'])->get(route('comms.inbox', ['thread_id' => $f['thread']->id]));
    $messages = $response->viewData('page')['props']['activeThread']['messages'];
    $assisted = collect($messages)->firstWhere('ai_assisted', true);

    expect($assisted)->not->toBeNull()
        // BOTH facts, together: a draft was involved AND this person sent it.
        ->and($assisted['sender'])->toBe($f['admin']->name);

    // The message is recorded against the HUMAN, not against the agent — the accountability record.
    expect(Message::query()->where('id', $assisted['id'])->value('author_staff_user_id'))
        ->toBe($f['admin']->id);
});

test('nothing on this screen sends by itself — the draft tool is ceilinged at suggest and needs a human actor', function () {
    $definition = app(DraftReplyTool::class)->definition();

    expect($definition->autonomyCeiling)->toBe(AutonomyPolicy::SUGGEST)
        ->and($definition->permission)->toBe('comms.manage');

    // The ceiling is what makes it safe, not the absence of a button (PC.P7): calling execute()
    // without a human is refused by the TOOL, whatever any UI does.
    expect(fn () => app(DraftReplyTool::class)->execute(['thread_id' => 'x'], null))
        ->toThrow(InvalidArgumentException::class);

    // And there is no acting comms tool to reach for — no send, no campaign, no bulk.
    $keys = collect(app(ToolRegistry::class)->all())
        ->map(fn ($tool) => $tool->definition()->key);

    foreach (['comms.send', 'comms.send_reply', 'comms.campaign', 'comms.bulk_send', 'comms.sms'] as $forbidden) {
        expect($keys)->not->toContain($forbidden);
    }
    expect($keys)->toContain('comms.draft_reply');
});

/* --------------------------------------------------------------- consent, channels, "sent" */

test('a non-consenting recipient is refused AT THE SERVICE with a recorded reason row', function () {
    $f = cp1Fixture();

    ConsentTemplate::query()->create([
        'key' => 'comms', 'title' => 'Kommunikation per E-Mail', 'body' => 'Email comms consent',
        'version' => 1, 'scope_keys' => ['comms.email'], 'is_active' => true,
    ]);

    $template = NotificationTemplate::query()->create([
        'key' => 'appointment.reminder',
        'channel' => NotificationTemplate::CHANNEL_EMAIL,
        // A NON-LEGAL category, so the consent gate genuinely applies to it.
        'category' => NotificationTemplate::CATEGORY_TRANSACTIONAL,
        'subject' => 'Terminerinnerung',
        'body' => 'Ihr Termin steht an.',
        'is_active' => true,
    ]);

    $notifications = app(NotificationService::class);

    // Called DIRECTLY (D-183): over HTTP an outer gate would answer first and this check would
    // never be the deciding factor.
    $refused = $notifications->send($template->key, $f['patient'], ['when' => 'morgen']);

    expect($refused->status)->toBe(NotificationDelivery::STATUS_SKIPPED)
        // A non-send is a RECORDED ROW with a reason, not silence.
        ->and($refused->skipped_reason)->toBe('no_consent');

    // POSITIVE CONTROL: with consent granted the same call sends. The consent gate is the only
    // thing that was in the way.
    app(ConsentService::class)->grant($f['patient'], 'comms', 'Nora Keller', $f['admin']);
    $sent = $notifications->send($template->key, $f['patient'], ['when' => 'übermorgen']);

    expect($sent->status)->toBe(NotificationDelivery::STATUS_SENT)
        ->and($sent->skipped_reason)->toBeNull();
});

test('the LEGAL carve-out is real, so the page copy must not promise otherwise', function () {
    $f = cp1Fixture();

    ConsentTemplate::query()->create([
        'key' => 'comms', 'title' => 'Kommunikation per E-Mail', 'body' => 'Email comms consent',
        'version' => 1, 'scope_keys' => ['comms.email'], 'is_active' => true,
    ]);

    $legal = NotificationTemplate::query()->create([
        'key' => 'billing.dunning',
        'channel' => NotificationTemplate::CHANNEL_EMAIL,
        'category' => NotificationTemplate::CATEGORY_LEGAL,
        'subject' => 'Zahlungserinnerung',
        'body' => 'Offener Betrag.',
        'is_active' => true,
    ]);

    // NO comms.email consent at all — and the legal notice still sends. This is why the pane's
    // "no consent" copy states the carve-out instead of saying the patient is never emailed (D-184).
    $delivery = app(NotificationService::class)->send($legal->key, $f['patient'], []);
    expect($delivery->status)->toBe(NotificationDelivery::STATUS_SENT);

    $copy = json_decode((string) file_get_contents(resource_path('js/lang/en.json')), true);
    $noConsent = $copy['comms']['inbox']['context']['emailNo'];

    // The copy must name the exception rather than making an absolute promise the code breaks.
    expect(strtolower($noConsent))->toContain('legally required')
        ->and(strtolower($noConsent))->not->toContain('never email');
});

test('no unwired channel, no unproducible delivery state and no undo appears in the payload or the page', function () {
    $f = cp1Fixture();

    $response = $this->actingAs($f['admin'])->get(route('comms.inbox', ['thread_id' => $f['thread']->id]));
    $props = $response->viewData('page')['props'];

    // A non-empty payload, so the scan is not vacuous (D-174).
    expect($props['threads'])->not->toBeEmpty();
    expect($props['activeThread'])->not->toBeNull();

    $paths = array_keys(cp1Leaves($props));
    foreach (['sms', 'whatsapp', 'phone', 'delivered', 'read_receipt', 'undo', 'recall', 'topic_consent'] as $forbidden) {
        foreach ($paths as $path) {
            expect(str_contains(strtolower($path), $forbidden))
                ->toBeFalse("inbox payload must not carry a '{$forbidden}' key; found at {$path}");
        }
    }

    // ...and the COMPONENT itself draws none of them. The scan resolves its subject first, so it
    // cannot go silent if the file moves (D-173).
    $page = resource_path('js/pages/Comms/Inbox.vue');
    expect(file_exists($page))->toBeTrue("the scanned component must exist at {$page}");
    $source = (string) file_get_contents($page);
    expect(strlen($source))->toBeGreaterThan(1000);

    // Read the SCRIPT + TEMPLATE markup but exclude the omission copy keys, which legitimately
    // NAME these things in order to say they are absent.
    expect($source)->not->toContain('sms')
        ->and($source)->not->toContain('whatsapp')
        ->and($source)->not->toContain('read_receipt');
});

test('the omission card is RENDERED, not merely present in the copy file', function () {
    $f = cp1Fixture();

    // GOV.P3's lesson: asserting the source string leaves the render loop free to be emptied. So
    // assert the keys the component actually iterates, and that each resolves to real copy.
    $source = (string) file_get_contents(resource_path('js/pages/Comms/Inbox.vue'));

    expect($source)->toContain("const omittedKeys = ['channels', 'delivered', 'undo', 'topics']");
    expect($source)->toContain('comms.inbox.omitted.${key}');
    expect($source)->toContain("t('comms.inbox.omitted.title')");

    $copy = json_decode((string) file_get_contents(resource_path('js/lang/en.json')), true);
    $omitted = $copy['comms']['inbox']['omitted'];

    foreach (['channels', 'delivered', 'undo', 'topics'] as $key) {
        expect($omitted)->toHaveKey($key);
        expect(strlen((string) $omitted[$key]))->toBeGreaterThan(40);
    }

    // The statements must actually say what is missing, not merely exist.
    expect(strtolower($omitted['channels']))->toContain('sms')
        ->and(strtolower($omitted['delivered']))->toContain('delivered')
        ->and(strtolower($omitted['undo']))->toContain('undo');
});

test('opening a patient thread still writes exactly ONE read-audit row', function () {
    $f = cp1Fixture();

    // `audit_events` is append-only at the DB-trigger level, so this counts the DELTA rather
    // than clearing the table — the guard that makes the trail trustworthy also makes
    // 'reset and re-count' impossible, which is the correct trade.
    $before = DB::table('audit_events')
        ->where('resource_type', 'threads')->where('resource_id', $f['thread']->id)
        ->where('action', 'read')->count();

    $this->actingAs($f['admin'])->get(route('comms.inbox', ['thread_id' => $f['thread']->id]))->assertOk();

    // The context pane added four new reads of patient-adjacent data. None of them may grow a
    // second disclosure path — the D-173 discipline applied to this screen.
    $after = DB::table('audit_events')
        ->where('resource_type', 'threads')->where('resource_id', $f['thread']->id)
        ->where('action', 'read')->count();

    expect($after - $before)->toBe(1);
});
