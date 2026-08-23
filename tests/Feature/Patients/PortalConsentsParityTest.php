<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Clinical\Models\Document;
use Modules\Clinical\Services\DocumentService;
use Modules\Comms\Models\NotificationDelivery;
use Modules\Comms\Models\NotificationTemplate;
use Modules\Comms\Services\NotificationService;
use Modules\Patients\Models\ConsentTemplate;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientConsent;
use Modules\Patients\Models\PatientContact;
use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Services\ConsentService;
use Modules\Patients\Services\PatientService;
use Modules\Patients\Services\PortalAccessService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * PT.P5 — Portal Consents.
 *
 * The fence here is legal rather than clinical, and it has two halves:
 *
 *   1. NOTHING COSMETIC (D-176). A consent control the patient can operate must move something
 *      real. The page therefore states, for each consent, what withdrawing it ACTUALLY does — and
 *      that sentence is written against the code that enforces the scope, not against the mock.
 *      Where the product has no such copy, the page says so instead of inventing a reassurance.
 *
 *   2. THE WITHDRAWAL MUST BITE, AT EVERY LAYER THAT CLAIMS TO ENFORCE IT (D-182/D-183). Each
 *      proof below is built so it would SUCCEED with its guard removed, and each is pinned where
 *      nothing outside it can answer first. That distinction is not academic: PT.P4 found two
 *      guards whose tests were being answered by an outer layer, so deleting the inner one stayed
 *      green.
 *
 * Only two scopes are enforced anywhere in CareOS — `portal.access` and `comms.email` — and both
 * are proven here at their own layer.
 */

function pcnCtx(): TenantContext
{
    return app(TenantContext::class);
}

/** Sign in the way a real portal request does (the PT.P1 lesson: the guard, not actingAs). */
function pcnSignIn($test, PortalAccount $account)
{
    Auth::guard('patient')->setUser($account);

    return $test;
}

/**
 * @param  list<string>  $scopes
 */
function pcnTemplate(string $key, string $title, array $scopes): ConsentTemplate
{
    return ConsentTemplate::query()->firstOrCreate(
        ['key' => $key, 'version' => 1],
        ['title' => $title, 'body' => $title.' consent', 'scope_keys' => $scopes, 'is_active' => true],
    );
}

/**
 * A multi-state fixture, verified by query in the first test below rather than assumed:
 * a SUPERSEDED portal grant that was withdrawn and re-granted (so the append-only history has
 * something to show), a live comms grant, and a control patient who must never appear.
 *
 * @return array{tenant: Tenant, staff: User, patient: Patient, control: Patient, account: PortalAccount, password: string, superseded: PatientConsent, portalConsent: PatientConsent, commsConsent: PatientConsent}
 */
function pcnFixture(): array
{
    $tenant = Tenant::query()->create(['name' => 'Alpha Clinic', 'slug' => 'alpha', 'region' => 'eu', 'status' => 'active']);
    pcnCtx()->set($tenant);

    $staff = User::factory()->forTenant($tenant)->twoFactorEnabled()->create(['name' => 'Andrea Lindenhof']);
    RoleAssignment::query()->create([
        'user_id' => $staff->id,
        'role_id' => Role::query()->where('key', 'org_admin')->firstOrFail()->id,
    ]);

    $patient = app(PatientService::class)->create([
        'first_name' => 'Erika', 'last_name' => 'Baumgartner', 'date_of_birth' => '1954-03-12', 'sex' => 'female',
    ]);
    $control = app(PatientService::class)->create([
        'first_name' => 'Viktor', 'last_name' => 'Odermatt', 'date_of_birth' => '1968-02-11', 'sex' => 'male',
    ]);

    PatientContact::query()->create([
        'patient_id' => $patient->id, 'type' => PatientContact::TYPE_EMAIL,
        'value' => 'erika.consents@example.test', 'is_primary' => true,
    ]);

    pcnTemplate('portal', 'Portal Access', ['portal.access']);
    pcnTemplate('comms', 'Email communications', ['comms.email']);

    $consents = app(ConsentService::class);

    // A withdrawn grant that was later re-granted. The old row must SURVIVE untouched — a consent
    // record is append-only, and the page shows the history rather than rewriting it.
    $superseded = $consents->grant($patient, 'portal', 'Erika Baumgartner', $staff);
    $consents->withdraw($superseded, 'Changed my mind while travelling.');

    $portalConsent = $consents->grant($patient, 'portal', 'Erika Baumgartner', $staff);
    $commsConsent = $consents->grant($patient, 'comms', 'Erika Baumgartner', $staff);

    // The control patient's own consents — nothing of theirs may reach Erika's screen.
    $consents->grant($control, 'portal', 'Viktor Odermatt', $staff);

    $password = 'secret-portal-password';
    $account = PortalAccount::query()->create([
        'patient_id' => $patient->id,
        'email' => 'erika.consents@example.test',
        'password' => bcrypt($password),
        'status' => PortalAccount::STATUS_ACTIVE,
    ]);

    return compact('tenant', 'staff', 'patient', 'control', 'account', 'password', 'superseded', 'portalConsent', 'commsConsent');
}

/**
 * GET /portal/consents as the patient, returning the Inertia props.
 *
 * @param  array<string, mixed>  $fx
 * @return array<string, mixed>
 */
function pcnProps($test, array $fx): array
{
    pcnCtx()->forget();

    return pcnSignIn($test, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->get(route('portal.consents'))
        ->assertOk()
        ->viewData('page')['props'];
}

/** Strip comments so the fence scans AFFORDANCES, not the prose documenting their absence. */
function pcnStrip(string $source): string
{
    $source = preg_replace('~/\*.*?\*/~s', ' ', $source) ?? $source;
    $source = preg_replace('~<!--.*?-->~s', ' ', $source) ?? $source;

    return strtolower(preg_replace('~(^|\s)//[^\n]*~m', '$1 ', $source) ?? $source);
}

test('the fixture is the multi-state one this gate needs, verified BY QUERY', function () {
    $fx = pcnFixture();
    pcnCtx()->set($fx['tenant']);

    $rows = PatientConsent::query()->where('patient_id', $fx['patient']->id)->get();

    expect($rows)->toHaveCount(3);
    expect($rows->where('status', PatientConsent::STATUS_GRANTED)->pluck('template_key')->sort()->values()->all())
        ->toBe(['comms', 'portal']);

    // The superseded row is still there, still withdrawn, still carrying its original grant time.
    $old = $rows->firstWhere('id', $fx['superseded']->id);
    expect($old)->not->toBeNull();
    expect($old->status)->toBe(PatientConsent::STATUS_WITHDRAWN)
        ->and($old->withdrawn_at)->not->toBeNull()
        ->and($old->granted_at)->not->toBeNull()
        ->and($old->captured_by)->toBe($fx['staff']->id);

    // The control patient has their own consent, so cross-patient leakage would be VISIBLE.
    expect(PatientConsent::query()->where('patient_id', $fx['control']->id)->count())->toBe(1);
});

test('PARITY — each consent states its purpose, its real consequence, and who recorded it', function () {
    $fx = pcnFixture();
    $props = pcnProps($this, $fx);

    // The full history is on screen — the withdrawn row too, not just what is live now.
    expect($props['consents'])->toHaveCount(3);

    $byId = collect($props['consents'])->keyBy('id');
    $live = $byId[$fx['portalConsent']->id];
    $comms = $byId[$fx['commsConsent']->id];
    $old = $byId[$fx['superseded']->id];

    expect($live['status'])->toBe(PatientConsent::STATUS_GRANTED)
        ->and($live['copy_key'])->toBe('portal')
        ->and($live['captured_by'])->toBe('Andrea Lindenhof')
        ->and($live['granted_at'])->not->toBeNull()
        ->and($comms['copy_key'])->toBe('comms')
        ->and($old['status'])->toBe(PatientConsent::STATUS_WITHDRAWN)
        ->and($old['withdrawn_at'])->not->toBeNull();

    // Nothing of the control patient's reaches this screen.
    $ids = collect($props['consents'])->pluck('id')->all();
    $foreign = PatientConsent::query()->where('patient_id', $fx['control']->id)->pluck('id')->all();
    foreach ($foreign as $foreignId) {
        expect(in_array($foreignId, $ids, true))->toBeFalse('another patient consent row reached this screen');
    }

    // Every described consent has BOTH halves of its copy — a purpose and a consequence.
    $en = json_decode((string) file_get_contents(base_path('resources/js/lang/en.json')), true);
    foreach (['portal', 'comms'] as $key) {
        expect($en['portal']['consents']['purposes'][$key] ?? '')->not->toBe('');
        expect($en['portal']['consents']['consequences'][$key] ?? '')->not->toBe('');
    }
});

test('a consent the product cannot describe gets an honest fallback, not an invented reassurance', function () {
    $fx = pcnFixture();
    pcnCtx()->set($fx['tenant']);

    /*
     * D-176, in the positive-control shape D-182 asks for: a consent template whose scope NOTHING
     * in CareOS enforces. If the page reused the generic reassurance for it, the patient would
     * read a consequence no code delivers. It must come back with NO copy key, and the page must
     * then say plainly that we cannot describe the effect.
     */
    pcnTemplate('research', 'Research participation', ['research.share']);
    $research = app(ConsentService::class)->grant($fx['patient'], 'research', 'Erika Baumgartner', $fx['staff']);

    $props = pcnProps($this, $fx);
    $row = collect($props['consents'])->firstWhere('id', $research->id);

    expect($row)->not->toBeNull();
    expect($row['copy_key'])->toBeNull()
        ->and($row['status'])->toBe(PatientConsent::STATUS_GRANTED);

    // ...and the described ones are unaffected, so `null` is a decision and not a broken lookup.
    expect(collect($props['consents'])->firstWhere('id', $fx['portalConsent']->id)['copy_key'])->toBe('portal');

    // The page's fallback exists and admits the limit rather than reassuring.
    $en = json_decode((string) file_get_contents(base_path('resources/js/lang/en.json')), true);
    $fallback = strtolower((string) ($en['portal']['consents']['consequenceUnknown'] ?? ''));
    expect($fallback)->toContain('cannot');
    expect($fallback)->toContain('ask the practice');

    $page = pcnStrip((string) file_get_contents(base_path('resources/js/pages/Portal/Consents.vue')));
    expect($page)->toContain('consequenceunknown');
    expect($page)->toContain('v-if="consent.copy_key"');
});

test('GATING PROOF A1 — withdrawing portal access locks the very NEXT portal request', function () {
    $fx = pcnFixture();

    // POSITIVE CONTROL (D-182): the portal answers this patient right now. Without the middleware's
    // consent check the request below would keep succeeding — that is what makes the 403 mean
    // something.
    pcnCtx()->forget();
    pcnSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->get(route('portal.home'))
        ->assertOk();

    // The withdrawal goes through the REAL portal control, with a reason, exactly as a patient does.
    pcnCtx()->forget();
    pcnSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->post(route('portal.consents.withdraw'), [
            'consent_id' => $fx['portalConsent']->id,
            'reason' => 'I no longer want an online account.',
        ])
        ->assertRedirect(route('portal.consents'));

    // The very next request — same session, same account — is refused.
    pcnCtx()->forget();
    pcnSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->get(route('portal.home'))
        ->assertForbidden();

    // ...and so is the consents screen itself: no back door to un-withdraw from inside.
    pcnCtx()->forget();
    pcnSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->get(route('portal.consents'))
        ->assertForbidden();
});

test('GATING PROOF A2 (D-183) — the SIGN-IN path re-checks the same consent, where no middleware can answer first', function () {
    $fx = pcnFixture();
    $service = app(PortalAccessService::class);

    /*
     * The page promises "you will not be able to sign back in". Over HTTP the portal-consent
     * middleware would refuse first, so a request-level test would stay green with this check
     * deleted — the PT.P4 lesson. Calling login() DIRECTLY puts nothing in front of it.
     *
     * POSITIVE CONTROL: the same call succeeds while the consent is live.
     */
    pcnCtx()->forget();
    expect($service->login($fx['account']->email, $fx['password'])->id)->toBe($fx['account']->id);

    pcnCtx()->set($fx['tenant']);
    app(ConsentService::class)->withdraw($fx['portalConsent']->refresh(), 'No longer want an online account.');

    pcnCtx()->forget();
    expect(fn () => $service->login($fx['account']->email, $fx['password']))
        ->toThrow(AuthorizationException::class);
});

test('GATING PROOF A3 (D-183) — the document-sharing service re-checks it too, on the staff side', function () {
    $fx = pcnFixture();
    pcnCtx()->set($fx['tenant']);

    $makeDoc = fn (string $title): Document => Document::query()->create([
        'patient_id' => $fx['patient']->id, 'category' => Document::CATEGORY_LETTER, 'title' => $title,
        'original_filename' => 'letter.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 512,
        'storage_path' => 'tenants/'.$fx['tenant']->id.'/documents/'.str($title)->slug().'.pdf',
        'shared_with_patient' => false, 'uploaded_by' => $fx['staff']->id, 'uploaded_at' => now(),
    ]);

    $documents = app(DocumentService::class);

    // POSITIVE CONTROL: with the consent live, sharing works — so the refusal below is the consent
    // check and not a broken call.
    expect($documents->shareWithPatient($makeDoc('Before'), $fx['staff'])->shared_with_patient)->toBeTrue();

    app(ConsentService::class)->withdraw($fx['portalConsent']->refresh(), 'Withdrawing portal access.');

    $after = $makeDoc('After');
    expect(fn () => $documents->shareWithPatient($after, $fx['staff']))
        ->toThrow(AuthorizationException::class);
    expect($after->refresh()->shared_with_patient)->toBeFalse();
});

test('GATING PROOF B — withdrawing comms consent refuses a send server-side, and the LEGAL carve-out the copy admits is real', function () {
    Notification::fake();
    $fx = pcnFixture();
    pcnCtx()->set($fx['tenant']);
    $notifications = app(NotificationService::class);

    /*
     * POSITIVE CONTROL (D-182): with the consent live this email SENDS. That also rules out the
     * gate ahead of it — the tenant email preference — being what answers instead of the consent
     * check.
     */
    $before = $notifications->send('appointment.reminder', $fx['patient'], ['starts_at' => '2026-09-01 09:00']);
    expect($before->status)->toBe(NotificationDelivery::STATUS_SENT);

    app(ConsentService::class)->withdraw($fx['commsConsent']->refresh(), 'Too many emails.');

    // A DIRECT service call — no portal middleware in front of it, nothing else able to refuse
    // first. The context differs, so the dedupe key cannot be what stops it.
    $after = $notifications->send('appointment.reminder', $fx['patient'], ['starts_at' => '2026-09-08 09:00']);
    expect($after->status)->toBe(NotificationDelivery::STATUS_SKIPPED)
        ->and($after->skipped_reason)->toBe('no_consent');

    /*
     * ...and the caveat the page prints is TRUE, not decoration: a notice the practice must send
     * by law still goes out. "We will never email you" would have been an over-claim, and this is
     * the assertion that keeps the copy honest.
     */
    $legal = $notifications->send('billing.dunning', $fx['patient'], [
        'body' => 'Invoice reminder', 'invoice' => 'INV-1', 'level' => '1',
    ]);
    expect($legal->category)->toBe(NotificationTemplate::CATEGORY_LEGAL)
        ->and($legal->status)->toBe(NotificationDelivery::STATUS_SENT);

    $en = json_decode((string) file_get_contents(base_path('resources/js/lang/en.json')), true);
    expect(strtolower((string) $en['portal']['consents']['consequences']['comms']))->toContain('by law');
});

test('the withdrawal is RECORDED — who, when and why — and the earlier grant is untouched', function () {
    $fx = pcnFixture();

    pcnCtx()->forget();
    pcnSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->post(route('portal.consents.withdraw'), [
            'consent_id' => $fx['commsConsent']->id,
            'reason' => 'Too many emails.',
        ])
        ->assertRedirect(route('portal.consents'));

    pcnCtx()->set($fx['tenant']);
    $row = $fx['commsConsent']->refresh();
    expect($row->status)->toBe(PatientConsent::STATUS_WITHDRAWN)
        ->and($row->withdrawn_at)->not->toBeNull()
        // Append-only: withdrawal does not erase the grant that came before it.
        ->and($row->granted_at)->not->toBeNull()
        ->and($row->signature['name'] ?? null)->toBe('Erika Baumgartner')
        ->and($row->captured_by)->toBe($fx['staff']->id);

    /*
     * The audit row carries the reason. DECODED, never byte-matched: MySQL 8 re-serialises JSON
     * columns, so a raw-substring assertion is green on MariaDB and red on CI (the PT.P1 lesson).
     */
    $events = DB::table('audit_events')
        ->where('action', 'consent.withdrawn')
        ->where('resource_id', $row->id)
        ->get();

    expect($events)->toHaveCount(1);
    $event = $events->first();
    $context = json_decode((string) $event->context, true);
    expect($event->reason)->toBe('Too many emails.')
        ->and($context['template_key'] ?? null)->toBe('comms')
        ->and($context['scope_keys'] ?? [])->toBe(['comms.email']);

    // A reason is NOT optional — an unexplained withdrawal is not a record.
    expect(fn () => app(ConsentService::class)->withdraw($fx['portalConsent']->refresh(), '   '))
        ->toThrow(InvalidArgumentException::class);

    // ...and the withdrawn row is still listed afterwards, with its withdrawal date.
    $props = pcnProps($this, $fx);
    $shown = collect($props['consents'])->firstWhere('id', $row->id);
    expect($shown['status'])->toBe(PatientConsent::STATUS_WITHDRAWN)
        ->and($shown['withdrawn_at'])->not->toBeNull()
        ->and($shown['granted_at'])->not->toBeNull();
});

test('a patient cannot withdraw a consent that is not theirs', function () {
    $fx = pcnFixture();
    pcnCtx()->set($fx['tenant']);
    $foreign = PatientConsent::query()->where('patient_id', $fx['control']->id)->firstOrFail();

    // POSITIVE CONTROL: it is granted and live right now, so only the patient_id clause refuses it.
    expect($foreign->status)->toBe(PatientConsent::STATUS_GRANTED);

    pcnCtx()->forget();
    pcnSignIn($this, $fx['account'])
        ->withSession(['portal_tenant_id' => $fx['tenant']->id])
        ->post(route('portal.consents.withdraw'), ['consent_id' => $foreign->id, 'reason' => 'Not mine.'])
        ->assertNotFound();

    pcnCtx()->set($fx['tenant']);
    expect($foreign->refresh()->status)->toBe(PatientConsent::STATUS_GRANTED);
});

test('every consent the page DESCRIBES is one the code actually enforces', function () {
    $fx = pcnFixture();
    pcnCtx()->set($fx['tenant']);

    /*
     * D-176 in structural form. A stated consequence is a promise about behaviour; if the scope
     * behind it is checked nowhere in the enforcing code, the control is decorative and the promise
     * is a fiction. So: every scope of every DESCRIBED consent must appear in a consent check
     * outside the tests.
     */
    $sources = collect([base_path('Modules'), base_path('app')])
        ->flatMap(function (string $dir): array {
            $files = [];
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = (string) file_get_contents((string) $file->getRealPath());
                }
            }

            return $files;
        });

    foreach (['portal', 'comms'] as $key) {
        $template = ConsentTemplate::query()->where('key', $key)->firstOrFail();
        expect($template->scope_keys)->not->toBeEmpty();

        foreach ($template->scope_keys as $scope) {
            $enforced = $sources->contains(fn (string $code): bool => str_contains($code, "'{$scope}')"));

            expect($enforced)->toBeTrue("consent scope '{$scope}' is described to the patient but enforced nowhere");
        }
    }
});

test('THE FENCE: the consents screen records and states — it never judges', function () {
    $fx = pcnFixture();
    $props = pcnProps($this, $fx);

    // POSITIVE CONTROL: three real consents in the payload, so the scan has something to scan.
    expect($props['consents'])->toHaveCount(3);

    $forbidden = [
        'riskscore', 'risklevel', 'severity', 'acuity', 'triage', 'urgency', 'recommended',
        'suggestedaction', 'privacyscore', 'compliancescore', 'confidence',
    ];

    $squashed = preg_replace('~[^a-z0-9]~', '', strtolower(json_encode($props) ?: '')) ?? '';
    expect(strlen($squashed))->toBeGreaterThan(200);
    foreach ($forbidden as $token) {
        expect(str_contains($squashed, $token))->toBeFalse("fence token '{$token}' appears in the consents payload");
    }

    // D-173 — the scan follows the file, and fails loudly if the file moves out from under it.
    $path = base_path('resources/js/pages/Portal/Consents.vue');
    expect(file_exists($path))->toBeTrue('Consents.vue is missing — this fence would scan nothing');
    $page = pcnStrip((string) file_get_contents($path));
    $squashedFile = preg_replace('~[^a-z0-9]~', '', $page) ?? '';
    foreach ($forbidden as $token) {
        expect(str_contains($squashedFile, $token))->toBeFalse("fence token '{$token}' appears in Consents.vue");
    }

    /*
     * D-169 — nothing may be tinted by a JUDGMENT about a consent. Binding style to the recorded
     * STATUS is identity, not judgment, and stays permitted — the same line PT.P3 drew for slot
     * selection.
     */
    $raw = (string) file_get_contents($path);
    preg_match_all('~:class="([^"]*)"~', $raw, $bindings);
    foreach ($bindings[1] ?? [] as $binding) {
        foreach (['risk', 'severity', 'urgen'] as $judgment) {
            expect(str_contains(strtolower($binding), $judgment))->toBeFalse("a class binding is driven by '{$judgment}'");
        }
    }
});

test('PT.P1 — the consents render still writes EXACTLY ONE read row', function () {
    $fx = pcnFixture();

    pcnProps($this, $fx);

    pcnCtx()->set($fx['tenant']);
    $rows = DB::table('audit_events')
        ->where('action', 'read')
        ->where('patient_id', $fx['patient']->id)
        ->pluck('context')
        ->filter(function ($context): bool {
            $decoded = json_decode((string) $context, true);

            return is_array($decoded) && ($decoded['surface'] ?? null) === 'portal_consents';
        });

    expect($rows)->toHaveCount(1);
});
