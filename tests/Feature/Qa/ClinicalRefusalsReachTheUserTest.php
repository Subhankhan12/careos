<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Clinical\Models\Order;
use Modules\Clinical\Models\OrderableItem;
use Modules\Clinical\Services\OrderService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * QA-FIX.13 — `QF11a-M1`: the two Clinical pages render the refusals they receive.
 *
 * THIS IS THE SERVER HALF, AND IT IS DELIBERATELY NOT THE WHOLE PROOF.
 * It proves the error bag REACHES the page (the Inertia `errors` prop), which is a payload assertion —
 * and QA-FIX.5a's payload test survived a template mutation for exactly that reason. The rendering half
 * lives in `resources/js/pages/Clinical/clinical-refusals.test.ts`, which asserts the component is really
 * in the template, on COMMENT-STRIPPED source. Neither half alone is sufficient; both are mutation-checked.
 *
 * WHAT WAS ACTUALLY BROKEN. QA-FIX.11a gave `clinical.orders.review` a narrow catch so a non-`resulted`
 * order refuses with 302 + an error bag instead of an HTTP 500. That fixed `Lab/Review.vue` and
 * simultaneously made the same refusal REACHABLE AND INVISIBLE on the two Clinical pages that post to the
 * same endpoint, because neither rendered an error bag at all. The server side below was already correct
 * before this gate — these tests pin it so the adoption cannot be undone from either end.
 */

function qfcrCtx(): TenantContext
{
    return app(TenantContext::class);
}

function qfcrTenant(string $slug): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => ucfirst($slug).' Clinic',
        'slug' => $slug,
        'region' => 'eu',
        'status' => 'active',
    ]);
    qfcrCtx()->set($tenant);

    return $tenant;
}

function qfcrUser(Tenant $tenant, string $role = 'doctor'): User
{
    qfcrCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
    ]);

    return $user;
}

function qfcrPatient(): Patient
{
    return app(PatientService::class)->create([
        'first_name' => 'Refusal',
        'last_name' => 'Probe',
        'date_of_birth' => '1984-02-02',
        'sex' => 'female',
    ]);
}

function qfcrItem(): OrderableItem
{
    return OrderableItem::query()->create([
        'category' => OrderableItem::CATEGORY_LAB,
        'code' => 'FBC',
        'name' => 'Full blood count',
        'specimen_or_modality' => 'Blood',
        'active' => true,
    ]);
}

/** An order left at `ordered` — the state `markReviewed()` refuses. */
function qfcrOrderedOrder(User $doctor, Patient $patient): Order
{
    return app(OrderService::class)->place($patient, null, qfcrItem(), [], $doctor);
}

/** The same order driven all the way to `resulted`, which is the state review ACCEPTS. */
function qfcrResultedOrder(User $doctor, Patient $patient): Order
{
    $orders = app(OrderService::class);
    $order = qfcrOrderedOrder($doctor, $patient);
    $orders->transition($order, Order::STATUS_COLLECTED, $doctor);
    $orders->recordResult($order->refresh(), ['value' => '4.2'], $doctor);

    return $order->refresh();
}

it('routes the review refusal to the CHART as an error bag the page can render', function () {
    $tenant = qfcrTenant('qfcr-chart');
    $doctor = qfcrUser($tenant);
    $patient = qfcrPatient();
    $order = qfcrOrderedOrder($doctor, $patient);

    $this->actingAs($doctor)
        ->post(route('clinical.orders.review'), ['order_id' => $order->id])
        ->assertRedirect()
        ->assertSessionHasErrors(['order']);

    /*
     * THE SERVER'S OWN SENTENCE, VERBATIM. The component renders `Object.values(bag)` with no authored
     * copy of its own (D-176), so what the user reads is exactly this string. Asserting the string here
     * means a reworded service refusal cannot silently change what the chart says.
     */
    $this->actingAs($doctor)
        ->get(route('clinical.chart', $patient->id))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clinical/Chart')
            ->where('errors.order', 'Only a resulted order can be marked reviewed.')
        );

    // THE REFUSAL STILL REFUSES — the 500 → 302 change weakened nothing.
    expect($order->refresh()->status)->toBe(Order::STATUS_ORDERED)
        ->and($order->reviewed_at)->toBeNull();
});

it('routes the same refusal to the ORDERS WORKLIST, which is the other consumer of that endpoint', function () {
    $tenant = qfcrTenant('qfcr-worklist');
    $doctor = qfcrUser($tenant);
    $patient = qfcrPatient();
    $order = qfcrOrderedOrder($doctor, $patient);

    $this->actingAs($doctor)
        ->post(route('clinical.orders.review'), ['order_id' => $order->id])
        ->assertSessionHasErrors(['order']);

    $this->actingAs($doctor)
        ->get(route('clinical.orders.worklist'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clinical/OrdersReview')
            ->where('errors.order', 'Only a resulted order can be marked reviewed.')
        );
});

it('renders NO alert on the success path — the positive control (D-174)', function () {
    $tenant = qfcrTenant('qfcr-ok');
    $doctor = qfcrUser($tenant);
    $patient = qfcrPatient();
    $order = qfcrResultedOrder($doctor, $patient);

    $this->actingAs($doctor)
        ->post(route('clinical.orders.review'), ['order_id' => $order->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    /*
     * An EMPTY bag, not merely a missing key. `RefusalNotice` renders nothing when the bag is empty, so
     * this is the assertion that proves the adoption cannot manufacture a refusal that did not occur —
     * the failure mode D-176 exists to prevent.
     */
    $this->actingAs($doctor)
        ->get(route('clinical.chart', $patient->id))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clinical/Chart')
            ->where('errors', [])
        );

    expect($order->refresh()->status)->toBe(Order::STATUS_REVIEWED)
        ->and($order->reviewed_at)->not->toBeNull();
});

it('also carries FIELD-keyed validation refusals, which is the other shape the component must render', function () {
    $tenant = qfcrTenant('qfcr-validate');
    $doctor = qfcrUser($tenant);
    $patient = qfcrPatient();
    $item = qfcrItem();

    /*
     * TWO SHAPES LAND IN ONE BAG AND THAT IS WHY THE COMPONENT READS ALL OF IT.
     * `withErrors` keys by DOMAIN (`order`, above); `$request->validate()` keys by FIELD (`clinical_note`,
     * here). A component that named keys would render one and silently drop the other — which is how a
     * page can look handled while half its refusals stay invisible.
     */
    $this->actingAs($doctor)
        ->post(route('clinical.orders.place'), [
            'patient_id' => $patient->id,
            'orderable_item_id' => $item->id,
            'clinical_note' => str_repeat('x', 2001), // max:2000
        ])
        ->assertSessionHasErrors(['clinical_note']);

    $this->actingAs($doctor)
        ->get(route('clinical.chart', $patient->id))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clinical/Chart')
            ->has('errors.clinical_note')
        );

    // Nothing was written by the refused request.
    expect(Order::query()->count())->toBe(0);
});
