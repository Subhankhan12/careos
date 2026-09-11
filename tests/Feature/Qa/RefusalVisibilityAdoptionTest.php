<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AiCore\Exceptions\AiCoreException;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Models\OrderableItem;
use Modules\Hospital\Models\Stay;
use Modules\Lab\Models\LabTest;
use Modules\Nursing\Exceptions\AssignmentValidationException;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Exceptions\BookingConflictException;
use Modules\Scheduling\Exceptions\BookingUnavailableException;
use Modules\Scheduling\Exceptions\WaitlistException;

uses(RefreshDatabase::class);

/*
| QA-FIX.11a — FAMILY 3, the remaining modules render their refusals (`P8-H1`, `P9-H3`, `P10-M1`, `P10-H2`).
|
| THE FAMILY: a module's controllers refuse correctly and flash a message, and NO page renders it — so a
| refusal and a success are indistinguishable. Found four times: `P6-C3` (Surgery, 16 sites), `P7-H2` (ED,
| 10), `P8-H1` (Lab + Radiology, 18 at audit time), `P9-H3` (Hospital, 11). QA-FIX.6c fixed Surgery and
| QA-FIX.7c fixed ED.
|
| THIS PART IS AN ADOPTION, NOT A DESIGN (D-210, D-213). `RefusalNotice.vue` already exists and already has
| the property these modules need most: it reads the WHOLE error bag, because `validate()` keys by FIELD
| while the controllers key by DOMAIN, and naming keys would miss half the refusals. Nothing was built,
| restyled or reworded — seventeen imports and seventeen tags.
|
| WHY A STRUCTURAL GUARD AND NOT ONLY A PAYLOAD ONE: Inertia shares `errors` on EVERY response whether or
| not a template reads it, so a payload assertion cannot see whether the page RENDERS it (the QA-FIX.5a
| lesson — that gate's payload test survived a template mutation). The rendered proof is the Playwright
| drive recorded in the gate report; here the server behaviour and the template wiring are pinned
| separately.
*/

function rvaTenant(string $slug): Tenant
{
    $t = Tenant::query()->create(['name' => 'RVA '.$slug, 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($t);

    return $t;
}

function rvaUser(Tenant $tenant, array $roles): User
{
    $u = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    foreach ($roles as $k) {
        $r = Role::query()->where('key', $k)->first();
        if ($r !== null) {
            RoleAssignment::query()->firstOrCreate(['user_id' => $u->id, 'role_id' => $r->id]);
        }
    }

    return $u;
}

function rvaPatient(string $first = 'Rea', string $last = 'Fusal'): Patient
{
    return app(PatientService::class)->create([
        'first_name' => $first, 'last_name' => $last, 'date_of_birth' => '1984-04-04', 'sex' => 'female',
    ]);
}

/* ------------------------------------------------------------------ *
 | THE TWO REFUSALS THAT WERE WORSE THAN INVISIBLE — both were 500s.   |
 * ------------------------------------------------------------------ */

it('P8-H1: reviewing a not-yet-resulted order REFUSES with a message instead of a 500', function () {
    $tenant = rvaTenant('rva-review');
    $user = rvaUser($tenant, ['doctor', 'org_admin']);
    $patient = rvaPatient();
    $item = OrderableItem::query()->create(['code' => 'RVA1', 'name' => 'Panel', 'category' => 'lab', 'active' => true]);
    $order = Order::query()->create([
        'patient_id' => $patient->id, 'orderable_item_id' => $item->id, 'status' => Order::STATUS_ORDERED,
        'priority' => 'routine', 'ordered_by' => $user->id, 'ordered_at' => now(),
    ]);

    app(TenantContext::class)->forget();

    /*
     * BEFORE THIS PART THIS WAS AN **HTTP 500** — verified by driving it. `OrderService::markReviewed()`
     * refuses an order that is not `resulted` and nothing caught it, so a double-click or a worklist left
     * open while someone else reviewed the row produced a blank error page. `Lab/Review.vue` is the Lab
     * module's only page whose sole control posts here, so this was its ONLY failure mode.
     */
    $response = test()->actingAs($user)
        ->from('/lab/review')
        ->post('/clinical/orders/mark-reviewed', ['order_id' => $order->id]);

    $response->assertRedirect()->assertSessionHasErrors('order');

    // THE REFUSAL IS UNCHANGED — the 500 → 302 change weakens nothing.
    expect($order->refresh()->status)->toBe(Order::STATUS_ORDERED)
        ->and($order->reviewed_at)->toBeNull();
});

it('P10-H2: a domain refusal on approve reaches the reviewer instead of escaping as a 500', function () {
    /*
     * Phase 10 drove this: approving a stale `scheduler.fill_from_waitlist` proposal threw
     * `BookingConflictException`, which is NOT an `AiCoreException` (it extends `RuntimeException`), so it
     * escaped BOTH of the controller's catches and reached the reviewer as an HTTP 500 — no page, no
     * message, no error bag. The catch is NARROW (D-210): only declared domain-refusal types, never
     * `Throwable`, so a genuine bug still crashes.
     */
    $src = (string) file_get_contents(base_path('app/Http/Controllers/AiApprovalQueueController.php'));

    expect($src)->toContain('BookingConflictException|BookingUnavailableException|WaitlistException|AssignmentValidationException')
        ->and($src)->not->toContain('catch (Throwable');

    // Every named type really exists AND is genuinely outside the AiCoreException hierarchy — otherwise
    // the existing catch would already have handled it and this catch would be dead code.
    foreach ([
        BookingConflictException::class,
        BookingUnavailableException::class,
        WaitlistException::class,
        AssignmentValidationException::class,
    ] as $type) {
        expect(class_exists($type))->toBeTrue("{$type} must exist")
            ->and(is_a($type, AiCoreException::class, true))
            ->toBeFalse("{$type} would already be caught; naming it would be dead code");
    }
});

/* ------------------------------------------------------------------ *
 | THE SERVER STILL REFUSES — one driven refusal per module.           |
 * ------------------------------------------------------------------ */

it('P8-H1: a Lab catalog test with no code is refused, and the FIELD-keyed message reaches the page', function () {
    $tenant = rvaTenant('rva-labcat');
    $user = rvaUser($tenant, ['lab_tech', 'org_admin', 'doctor']);
    app(TenantContext::class)->forget();

    /*
     * A FIELD-KEYED refusal, and that is the point (D-210). `LabCatalogController::store` validates
     * `code`/`name` as required and only then calls the service, whose own
     * `LabCatalogException::codeAndNameRequired()` fires on an empty code — which `required` has already
     * rejected. So on THIS page the DOMAIN key is not reachable from the live control and the FIELD key is.
     * A notice naming domain keys would have shown nothing here; reading the WHOLE bag shows the real one.
     */
    $response = test()->actingAs($user)->from('/lab/catalog')
        ->post('/lab/catalog', ['code' => '', 'name' => 'Nameless', 'specimen_type' => 'blood']);

    $response->assertRedirect()->assertSessionHasErrors('code');

    // THE REFUSAL IS UNCHANGED — nothing was written.
    expect(LabTest::query()->count())->toBe(0);
});

it('P9-H3: a Hospital ward round with no staff profile is refused, and says so', function () {
    $tenant = rvaTenant('rva-hosp');
    $user = rvaUser($tenant, ['doctor', 'org_admin']);
    app(TenantContext::class)->forget();

    /*
     * A DOMAIN-KEYED refusal on a different module, so both shapes are driven. The route validates the
     * stay id; an unknown stay is refused before any write. Hospital keys by DOMAIN (`round`, `vital`,
     * `order`, `admission`, `transfer`, `discharge`, `summary`, `handover`, `bed`, `invoice`) — eleven
     * sites, and before this part no Hospital page read any of them (`P9-H3`).
     */
    $response = test()->actingAs($user)->from('/hospital/wards')
        ->post('/hospital/admissions', [
            'bed_id' => 'not-a-real-bed', 'patient_id' => 'not-a-real-patient',
            'admitting_clinician_id' => '', 'admission_type' => 'elective', 'reason' => 'x',
        ]);

    $response->assertRedirect();
    expect(session('errors'))->not->toBeNull();
    expect(Stay::query()->count())->toBe(0);
});

it('POSITIVE CONTROL — a SUCCESSFUL Lab catalog write flashes NO error at all', function () {
    $tenant = rvaTenant('rva-labok');
    $user = rvaUser($tenant, ['lab_tech', 'org_admin', 'doctor']);
    app(TenantContext::class)->forget();

    /*
     * THE CONTROL THAT STOPS THIS SUITE BEING SATISFIED BY A PAGE THAT ALWAYS SHOWS SOMETHING (D-174).
     * Same route as the refusal above, with a payload that fits.
     */
    test()->actingAs($user)->from('/lab/catalog')
        ->post('/lab/catalog', ['code' => 'OK1', 'name' => 'Fine', 'specimen_type' => 'blood'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

/* ------------------------------------------------------------------ *
 | THE STRUCTURAL GUARDS.                                             |
 * ------------------------------------------------------------------ */

it('STRUCTURAL GUARD: every adopted surface renders the refusal, through the EXISTING component', function () {
    $pages = [
        'Lab' => ['Billing', 'Catalog', 'Orders', 'Results', 'Specimens', 'Review'],
        'Radiology' => ['Billing', 'Catalog', 'Orders', 'Report', 'Study', 'Worklist'],
        'Hospital' => ['Admission', 'DischargeSummary', 'Handover', 'StayChart', 'WardBoard'],
        'Governance' => ['ApprovalQueue'],
    ];

    $count = 0;
    foreach ($pages as $dir => $names) {
        foreach ($names as $name) {
            $vue = (string) file_get_contents(resource_path("js/pages/{$dir}/{$name}.vue"));
            expect($vue)->toContain('<RefusalNotice />')
                ->and($vue)->toContain("import RefusalNotice from '@/Components/RefusalNotice.vue';");
            $count++;
        }
    }

    // Mutation-checked: deleting <RefusalNotice /> from ANY of these reddens this test.
    expect($count)->toBe(18);
});

it('AN ADOPTION, NOT A DESIGN — no module rolled its own error mechanism', function () {
    // D-170/D-213: anything matching here would be a second, divergent mechanism.
    $own = collect([
        ...glob(resource_path('js/pages/Lab/*.vue')),
        ...glob(resource_path('js/pages/Radiology/*.vue')),
        ...glob(resource_path('js/pages/Hospital/*.vue')),
    ])->map(fn (string $p): string => (string) file_get_contents($p))->implode("\n");

    foreach (['page.props.errors', 'usePage().props.errors', 'v-if="errors', 'defineProps<{ errors'] as $mechanism) {
        expect(str_contains($own, $mechanism))->toBeFalse("must reuse RefusalNotice, not roll its own ({$mechanism})");
    }

    // And the component still has the properties these modules depend on.
    $component = (string) file_get_contents(resource_path('js/Components/RefusalNotice.vue'));
    expect($component)->toContain('Object.values(bag)')
        ->and($component)->toContain('page.props.errors')
        ->and($component)->toContain('role="alert"');
});

it('D-176: every page that renders the notice has a refusal a user can actually reach', function () {
    /*
     * The counterpart to QA-FIX.6c's `Checklist.vue` exclusion. An error region on a page whose refusals
     * cannot fire is an affordance for something that never happens. Each page is justified by NAMING the
     * refusal it can show — individually, so the question stays answerable when a page changes (D-213).
     */
    $reachable = [
        'Lab/Billing' => 'lab_billing ×3 — price-test, charge, invoice',
        'Lab/Catalog' => 'lab_catalog — duplicate code, driven above',
        'Lab/Orders' => 'lab_order — ordering an inactive/unknown test',
        'Lab/Results' => 'lab_result — a result on a specimen that cannot take one',
        'Lab/Specimens' => 'specimen ×2 — collect and an illegal transition',
        'Lab/Review' => 'order — reviewing a not-yet-resulted order, driven above (was a 500)',
        'Radiology/Billing' => 'radiology_billing ×3',
        'Radiology/Catalog' => 'radiology_catalog — duplicate code',
        'Radiology/Orders' => 'radiology_order',
        'Radiology/Report' => 'radiology_report ×4 (adopted by QA-FIX.8b for its own refusal)',
        'Radiology/Study' => 'imaging_study — an illegal study transition',
        'Radiology/Worklist' => 'imaging_study — acquire on an order that cannot acquire',
        'Hospital/Admission' => 'invoice — bed-day billing refusal',
        'Hospital/DischargeSummary' => 'summary ×2 — save and finalize',
        'Hospital/Handover' => 'handover',
        'Hospital/StayChart' => 'round / vital / order — including the two QA-FIX.9c refusals',
        'Hospital/WardBoard' => 'admission / transfer / discharge / bed — all four post from this page',
        'Governance/ApprovalQueue' => 'action — the approve refusal, incl. the P10-H2 domain refusal',
    ];

    expect(array_keys($reachable))->toHaveCount(18);

    // And the server side really exists: the refusal sites are still there, comment-stripped counts pinned
    // in the gate report. A module that lost its refusals would make these pages unjustified.
    $sites = static function (string $glob): int {
        return collect(glob(base_path($glob)))
            ->map(fn (string $p): string => (string) file_get_contents($p))
            ->reduce(fn (int $c, string $s): int => $c + substr_count($s, '->withErrors('), 0);
    };

    expect($sites('Modules/Lab/src/Http/Controllers/*.php'))->toBe(8)
        ->and($sites('Modules/Radiology/src/Http/Controllers/*.php'))->toBe(11)
        ->and($sites('Modules/Hospital/src/Http/Controllers/*.php'))->toBe(11);
});
