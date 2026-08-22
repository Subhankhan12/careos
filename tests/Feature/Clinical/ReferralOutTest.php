<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Clinical\Models\Allergy;
use Modules\Clinical\Models\Referral;
use Modules\Clinical\Services\ReferralService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientAccessReport;
use Modules\Patients\Services\PatientService;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * PC.P6 — Referral Out: net-new UI over the EXISTING referral backend.
 *
 * `ReferralRecallTest` already covers the backend invariants (tenant isolation, RBAC, the audited
 * lifecycle) and is NOT touched here. What these tests pin is the new surface and its fences:
 *
 *  1. THE SCREEN SHOWS REAL REFERRALS IN REAL STATES — nothing fabricated, honest when empty.
 *  2. EVERY TRANSITION GOES THROUGH `ReferralService`. The controller writes no status of its own,
 *     and the service's rules still bite through the new routes (an out-of-order transition fails).
 *  3. THE DISCLOSURE IS AUDITED THROUGH THE EXISTING PATH — exactly one read row per render, so it
 *     appears in the PC.P5 access log without a second audit path.
 *  4. NO COMPUTED CLINICAL JUDGMENT. No urgency, priority, triage, ranking or suggested specialist
 *     anywhere — and none exists in the backend either, which is the honest reason the wireframe's
 *     urgency chip is absent rather than invented (D-170).
 *
 * The fixture is REPRESENTATIVE (D-174): a referral in EVERY real state plus a SEVERE recorded
 * allergy — the data that would tempt a system inclined to rank or tint.
 */

function routCtx(): TenantContext
{
    return app(TenantContext::class);
}

function routUser(Tenant $tenant, string $role): User
{
    routCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
    ]);

    return $user;
}

/**
 * @return array{tenant: Tenant, clinician: User, patient: Patient, branch: Branch, referrals: array<string, Referral>}
 */
function routFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Clinic', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    routCtx()->set($tenant);

    $clinician = routUser($tenant, 'doctor');
    $branch = Branch::query()->create(['name' => strtoupper($slug).' Branch', 'code' => strtoupper(substr($slug, 0, 4))]);
    $patient = app(PatientService::class)->create([
        'first_name' => 'Markus', 'last_name' => 'Roth', 'date_of_birth' => '1973-06-02', 'sex' => 'male',
    ]);

    $service = app(ReferralService::class);

    // One referral in EVERY real state, created only through the real service.
    $draft = $service->create($patient, $clinician, [
        'to_provider_name' => 'Dr. R. Habegger',
        'specialty' => 'Oral & maxillofacial surgery',
        'reason' => 'Non-healing socket at tooth 36, ten days post-extraction. Assessment requested.',
    ]);

    $sent = $service->create($patient, $clinician, ['to_provider_name' => 'Dr. M. Suter', 'specialty' => 'Cardiology', 'reason' => 'Intermittent palpitations over six weeks.']);
    $service->send($sent, $clinician);

    $accepted = $service->create($patient, $clinician, ['to_provider_name' => 'Dr. L. Frei', 'specialty' => 'Dermatology', 'reason' => 'Persistent eczematous rash on both forearms.']);
    $service->send($accepted, $clinician);
    $service->respond($accepted, Referral::STATUS_ACCEPTED, $clinician, 'Appointment offered.');

    $completed = $service->create($patient, $clinician, ['to_provider_name' => 'Dr. S. Weber', 'specialty' => 'Ophthalmology', 'reason' => 'Routine diabetic retinal screening.']);
    $service->send($completed, $clinician);
    $service->respond($completed, Referral::STATUS_ACCEPTED, $clinician);
    $service->complete($completed, $clinician);

    $declined = $service->create($patient, $clinician, ['to_provider_name' => 'Dr. K. Roth', 'specialty' => 'Neurology', 'reason' => 'Episodic headache with visual aura.']);
    $service->send($declined, $clinician);
    $service->respond($declined, Referral::STATUS_DECLINED, $clinician, 'Referred on to the regional centre.');

    // The tempting data: a SEVERE recorded allergy.
    $staff = StaffProfile::query()->create([
        'first_name' => 'Anna', 'last_name' => 'Vogt', 'display_name' => 'Dr. A. Vogt',
        'profession' => 'doctor', 'primary_branch_id' => $branch->id,
    ]);
    Allergy::query()->create([
        'patient_id' => $patient->id,
        'substance' => 'Penicillin',
        'substance_key' => 'penicillin',
        'reaction' => 'Anaphylaxis requiring adrenaline.',
        'severity' => Allergy::SEVERITY_SEVERE,
        'status' => Allergy::STATUS_ACTIVE,
        'recorded_by' => $staff->id,
        'recorded_at' => now(),
    ]);

    return [
        'tenant' => $tenant,
        'clinician' => $clinician,
        'patient' => $patient,
        'branch' => $branch,
        'referrals' => compact('draft', 'sent', 'accepted', 'completed', 'declined'),
    ];
}

/**
 * Strip comments so the scans test AFFORDANCES, not the prose documenting their absence.
 *
 * The `clinical.referrals.scope*` i18n references are stripped for the SAME reason: they are the
 * on-screen statements that urgency, a packet and a provider directory do NOT exist, so the key
 * named `scopeUrgency` must not be what fails the test banning an urgency affordance (the P1/P3/P5
 * lesson, again). Their TEXT still lives in en.json and is asserted verbatim by the D-170 test, so
 * nothing is hidden — and every other use of the token in these files still fails the scan.
 */
function routStrip(string $source): string
{
    $source = preg_replace('~/\*.*?\*/~s', ' ', $source) ?? $source;
    $source = preg_replace('~<!--.*?-->~s', ' ', $source) ?? $source;
    $source = preg_replace("~t\('clinical\.referrals\.scope[A-Za-z]*'\)~", ' ', $source) ?? $source;

    return strtolower(preg_replace('~(^|\s)//[^\n]*~m', '$1 ', $source) ?? $source);
}

test('the screen renders the patient REAL referrals in their REAL states', function () {
    $fx = routFixture();

    routCtx()->forget();
    $this->actingAs($fx['clinician'])
        ->get(route('clinical.referrals', $fx['patient']->id))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($fx) {
            $page->component('Clinical/Referrals');
            $rows = collect($page->toArray()['props']['referrals']);

            // POSITIVE CONTROL: a non-empty fixture covering every real state (D-174).
            expect($rows)->toHaveCount(5);
            $statuses = $rows->pluck('status')->sort()->values()->all();
            expect($statuses)->toBe(['accepted', 'completed', 'declined', 'draft', 'sent']);

            // Every rendered row is a REAL row — ids match what the service created.
            $expectedIds = collect($fx['referrals'])->pluck('id')->sort()->values()->all();
            expect($rows->pluck('id')->sort()->values()->all())->toBe($expectedIds);

            // Newest first, by a RECORDED timestamp — never by a computed importance.
            $created = $rows->pluck('created_at')->all();
            $sorted = $created;
            rsort($sorted);
            expect($created)->toBe($sorted);

            // The transition affordances mirror the SERVICE's rules.
            expect($rows->firstWhere('status', 'draft')['can_send'])->toBeTrue();
            expect($rows->firstWhere('status', 'sent')['can_respond'])->toBeTrue();
            expect($rows->firstWhere('status', 'accepted')['can_complete'])->toBeTrue();
            expect($rows->firstWhere('status', 'completed')['can_send'])->toBeFalse();

            return true;
        });
});

test('a patient with no referrals gets an honest empty list, not a fabricated one', function () {
    $fx = routFixture();
    routCtx()->set($fx['tenant']);
    $other = app(PatientService::class)->create([
        'first_name' => 'Nina', 'last_name' => 'Neu', 'date_of_birth' => '1990-01-01', 'sex' => 'female',
    ]);

    routCtx()->forget();
    $this->actingAs($fx['clinician'])
        ->get(route('clinical.referrals', $other->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('referrals', []));

    $en = json_decode((string) file_get_contents(base_path('resources/js/lang/en.json')), true);
    expect($en['clinical']['referrals']['empty'])->toContain('No referrals recorded');
});

test('every transition goes through the EXISTING service — the page writes no status', function () {
    $fx = routFixture();
    $draft = $fx['referrals']['draft'];

    routCtx()->forget();
    $this->actingAs($fx['clinician'])
        ->post(route('clinical.referrals.send', $draft->id))
        ->assertRedirect();

    routCtx()->set($fx['tenant']);
    $afterSend = Referral::query()->whereKey($draft->id)->firstOrFail();
    expect($afterSend->status)->toBe(Referral::STATUS_SENT)
        ->and($afterSend->sent_at)->not->toBeNull();

    // The SERVICE's rules still bite through the new route: a sent referral cannot be re-sent.
    routCtx()->forget();
    $this->actingAs($fx['clinician'])
        ->post(route('clinical.referrals.send', $draft->id))
        ->assertStatus(500);

    // ...and a draft cannot skip straight to a response.
    routCtx()->set($fx['tenant']);
    $fresh = app(ReferralService::class)->create($fx['patient'], $fx['clinician'], ['reason' => 'Fresh draft.']);
    routCtx()->forget();
    $this->actingAs($fx['clinician'])
        ->post(route('clinical.referrals.respond', $fresh->id), ['status' => Referral::STATUS_ACCEPTED])
        ->assertStatus(500);

    routCtx()->set($fx['tenant']);
    expect(Referral::query()->whereKey($fresh->id)->firstOrFail()->status)->toBe(Referral::STATUS_DRAFT);

    /*
     * THE CONTROLLER CANNOT WRITE A STATE. No save/update/forceFill anywhere — every transition is
     * a call into the service, which owns the state machine.
     */
    $controller = routStrip((string) file_get_contents(base_path('Modules/Clinical/src/Http/Controllers/ReferralController.php')));
    expect(strlen(trim($controller)))->toBeGreaterThan(400);
    foreach (['->save(', '->update(', 'forcefill(', '->delete(', 'db::table', 'db::statement'] as $write) {
        expect(str_contains($controller, $write))->toBeFalse("the referral controller writes state directly: '{$write}'");
    }
    foreach (['referrals->send(', 'referrals->respond(', 'referrals->complete(', 'referrals->create('] as $call) {
        expect($controller)->toContain($call);
    }
});

test('the disclosure is audited through the EXISTING path and appears in the access log', function () {
    $fx = routFixture();

    routCtx()->set($fx['tenant']);
    $before = app(PatientAccessReport::class)->forPatientNewestFirst($fx['patient'])->count();

    routCtx()->forget();
    $this->actingAs($fx['clinician'])->get(route('clinical.referrals', $fx['patient']->id))->assertOk();

    routCtx()->set($fx['tenant']);
    $after = app(PatientAccessReport::class)->forPatientNewestFirst($fx['patient']);

    // EXACTLY ONE row per render (the PC.P1/PC.P5 rule) — not one per referral.
    expect($after->count())->toBe($before + 1);
    expect(json_decode((string) $after->first()->context, true)['surface'])->toBe('referrals');

    // ONE audit path in the controller: no second mechanism was introduced.
    $controller = routStrip((string) file_get_contents(base_path('Modules/Clinical/src/Http/Controllers/ReferralController.php')));
    expect(substr_count($controller, 'auditread('))->toBe(1);
    expect(str_contains($controller, 'audit::record'))->toBeFalse('a second audit path was introduced');
});

test('the screen and the chart rail agree about this patient referrals', function () {
    $fx = routFixture();

    routCtx()->forget();
    $screen = collect($this->actingAs($fx['clinician'])
        ->get(route('clinical.referrals', $fx['patient']->id))
        ->assertOk()
        ->viewData('page')['props']['referrals']);

    routCtx()->forget();
    $chart = collect($this->actingAs($fx['clinician'])
        ->get(route('clinical.chart', $fx['patient']->id))
        ->assertOk()
        ->viewData('page')['props']['referrals']);

    // POSITIVE CONTROL: both surfaces actually returned rows.
    expect($screen)->not->toBeEmpty()
        ->and($chart)->not->toBeEmpty();

    // Same referrals, same statuses — one backend, two views, no divergence.
    expect($screen->pluck('id')->sort()->values()->all())->toBe($chart->pluck('id')->sort()->values()->all());
    expect($screen->sortBy('id')->pluck('status')->values()->all())
        ->toBe($chart->sortBy('id')->pluck('status')->values()->all());
});

test('the screen is permission-gated, write-gated and fails closed across tenants', function () {
    $fx = routFixture('alpha');

    // billing holds neither patient.view nor note.write — refused outright.
    $billing = routUser($fx['tenant'], 'billing');
    routCtx()->forget();
    $this->actingAs($billing)->get(route('clinical.referrals', $fx['patient']->id))->assertForbidden();

    // A cross-tenant patient and a cross-tenant referral both fail closed.
    $beta = routFixture('beta');
    routCtx()->forget();
    $this->actingAs($beta['clinician'])->get(route('clinical.referrals', $fx['patient']->id))->assertNotFound();
    routCtx()->forget();
    $this->actingAs($beta['clinician'])
        ->post(route('clinical.referrals.send', $fx['referrals']['draft']->id))
        ->assertNotFound();

    // The alpha draft was not touched by beta's attempt.
    routCtx()->set($fx['tenant']);
    expect(Referral::query()->whereKey($fx['referrals']['draft']->id)->firstOrFail()->status)
        ->toBe(Referral::STATUS_DRAFT);

    /*
     * Writing needs `note.write`, and the SERVICE is what enforces it. Reception DOES hold
     * `patient.view`, so it gets past the screen's view gate and is refused by the service —
     * an AuthorizationException, which surfaces as 403. That is the interesting case: the
     * write gate is the service's, not a UI affordance, and it holds through the new route.
     */
    $reception = routUser($fx['tenant'], 'reception');
    routCtx()->forget();
    $this->actingAs($reception)->get(route('clinical.referrals', $fx['patient']->id))->assertOk();
    routCtx()->forget();
    $this->actingAs($reception)
        ->post(route('clinical.referrals.store', $fx['patient']->id), ['reason' => 'Attempted by reception.'])
        ->assertForbidden();
    routCtx()->forget();
    $this->actingAs($reception)
        ->post(route('clinical.referrals.send', $fx['referrals']['draft']->id))
        ->assertForbidden();
    // ...and the page does not offer them the affordance either.
    routCtx()->forget();
    $this->actingAs($reception)
        ->get(route('clinical.referrals', $fx['patient']->id))
        ->assertInertia(fn (Assert $page) => $page->where('actions.can_write', false));

    routCtx()->set($fx['tenant']);
    expect(Referral::query()->where('patient_id', $fx['patient']->id)->count())->toBe(5);
});

test('D-170: the fields the wireframe draws with no backend are ABSENT, not invented', function () {
    // POSITIVE CONTROL: the table really exists and really has the columns it does have.
    expect(Schema::hasTable('referrals'))->toBeTrue()
        ->and(Schema::hasColumn('referrals', 'reason'))->toBeTrue()
        ->and(Schema::hasColumn('referrals', 'to_provider_name'))->toBeTrue();

    // No urgency/priority column — so no urgency is shown, and nothing can be tinted by one.
    foreach (['urgency', 'priority', 'triage', 'severity', 'score', 'rank'] as $absent) {
        expect(Schema::hasColumn('referrals', $absent))->toBeFalse("referrals grew a '{$absent}' column");
    }

    // No provider directory and no referral attachment table were invented.
    foreach (['external_providers', 'referral_providers', 'provider_directory', 'referral_attachments', 'referral_documents'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("a '{$table}' table was invented to satisfy the wireframe");
    }

    // No agent tool reaches referrals. POSITIVE CONTROL: the tool fleet really resolved.
    $tools = glob(base_path('app/AiCore/Tools/*.php')) ?: [];
    expect(count($tools))->toBeGreaterThanOrEqual(10);
    foreach ($tools as $tool) {
        $code = routStrip((string) file_get_contents($tool));
        expect(str_contains($code, 'referralservice'))->toBeFalse(basename($tool).' reaches the referral service');
        expect(str_contains($code, 'models\referral'))->toBeFalse(basename($tool).' reaches the referral model');
    }

    // And the page states each omission instead of leaving it a silent gap.
    $en = json_decode((string) file_get_contents(base_path('resources/js/lang/en.json')), true);
    $scope = $en['clinical']['referrals'];
    expect($scope['scopeTransmit'])->toContain('does NOT transmit')
        ->and($scope['scopeUrgency'])->toContain('none is recorded')
        ->and($scope['scopePacket'])->toContain('no attachments')
        ->and($scope['scopeDirectory'])->toContain('no provider directory');
});

test('THE FENCE: no computed urgency, ranking or judgment anywhere on the referral surface', function () {
    $fx = routFixture();

    routCtx()->forget();
    $props = $this->actingAs($fx['clinician'])
        ->get(route('clinical.referrals', $fx['patient']->id))
        ->assertOk()
        ->viewData('page')['props'];

    // POSITIVE CONTROL: a NON-EMPTY payload carrying every state and a SEVERE allergy — exactly
    // what a system inclined to rank or tint would act on (D-174).
    expect($props['referrals'])->toHaveCount(5)
        ->and($props['allergies'])->toHaveCount(1)
        ->and($props['allergies'][0]['severity'])->toBe('severe');

    $forbidden = [
        'urgency', 'urgencylevel', 'priorityscore', 'priority', 'triage', 'acuity', 'ews',
        'riskscore', 'risklevel', 'suggestedspecialist', 'recommendedprovider', 'rankedproviders',
        'matchscore', 'severityband', 'autoselected', 'waittime', 'triagetime',
    ];

    $squashed = preg_replace('~[^a-z0-9]~', '', strtolower(json_encode($props) ?: '')) ?? '';
    expect(strlen($squashed))->toBeGreaterThan(400);
    foreach ($forbidden as $token) {
        expect(str_contains($squashed, $token))->toBeFalse("fence token '{$token}' appears in the referral payload");
    }

    // D-173 — the scan follows every path this gate created or reused.
    $files = [
        base_path('resources/js/pages/Clinical/Referrals.vue'),
        base_path('Modules/Clinical/src/Http/Controllers/ReferralController.php'),
    ];

    foreach ($files as $path) {
        expect(file_exists($path))->toBeTrue(basename($path).' is missing — this fence would scan nothing');
        $code = routStrip((string) file_get_contents($path));
        expect(strlen(trim($code)))->toBeGreaterThan(400, basename($path).' stripped to almost nothing');

        $squashedFile = preg_replace('~[^a-z0-9]~', '', $code) ?? '';
        foreach ($forbidden as $token) {
            expect(str_contains($squashedFile, $token))->toBeFalse("fence token '{$token}' appears in ".basename($path));
        }

        /*
         * D-169 — nothing may be styled by a clinical value OR by the referral's status. A
         * declined referral must not read as alarming and a completed one must not read as
         * reassuring: the pill states a recorded lifecycle fact and ranks nothing.
         */
        preg_match_all('~:(?:class|style)="([^"]*)"~', $code, $bindings);
        foreach ($bindings[1] ?? [] as $binding) {
            foreach (['status', 'severity', 'urgency', 'priority', 'risk', 'allergy'] as $needle) {
                expect(str_contains($binding, $needle))->toBeFalse(basename($path)." styles by a clinical or lifecycle value: {$binding}");
            }
            expect(preg_match('~[<>]=?\s*\d~', $binding))->toBe(0, basename($path)." styles by a threshold: {$binding}");
        }

        // D-172 — nothing draws.
        foreach (['<canvas', 'getcontext', 'fillrect', 'drawimage'] as $drawing) {
            expect(str_contains($code, $drawing))->toBeFalse(basename($path)." draws: '{$drawing}'");
        }
    }
});
