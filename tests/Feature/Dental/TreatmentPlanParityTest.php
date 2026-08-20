<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Dental\Models\DentalProcedure;
use Modules\Dental\Services\TreatmentPlanService;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * DENTAL-B.P4 — Treatment Plan visual parity.
 *
 * Two fences, different from P2/P3:
 *
 *  1. MONEY. Every figure — the plan estimate, per-phase totals, "N of M billed" — must be
 *     ENGINE-computed and merely DISPLAYED. The page receives already-formatted strings and
 *     performs no arithmetic at all, not even a divide-by-100. Figures must TIE to the engine
 *     to the unit (delta = 0).
 *  2. THE AGENT. The mock shows an agent drafting and pricing a sequence. No treatment-plan
 *     agent tool exists in the repo, so the affordance is OMITTED rather than invented — and
 *     these tests pin that absence so a later gate cannot quietly auto-apply one.
 *
 * Plus the clinical fence: no recommended pathway, no prognosis, no auto-selected procedure,
 * no urgency/priority score — including the D-169 styling rule, because a ramp needs no word.
 */

function tppCtx(): TenantContext
{
    return app(TenantContext::class);
}

function tppUser(Tenant $tenant, string $role): User
{
    tppCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

/**
 * A tenant with a dentist, a patient, a branch and two priced procedures.
 *
 * @return array{tenant: Tenant, dentist: User, patient: Patient, branch: Branch, crown: DentalProcedure, prophy: DentalProcedure}
 */
function tppFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Dental', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    tppCtx()->set($tenant);

    // org_admin holds BOTH dental.chart and billing.manage — the dentist-owner.
    $dentist = tppUser($tenant, 'org_admin');
    $patient = app(PatientService::class)->create(['first_name' => 'Anna', 'last_name' => 'Vogel', 'date_of_birth' => '1979-05-22', 'sex' => 'female']);
    $branch = Branch::query()->create(['name' => 'Zurich', 'code' => 'ZRH', 'timezone' => 'Europe/Zurich']);
    // The DISPLAY currency is a tenant setting (the tariff item carries its own).
    app(SettingsService::class)->set('currency', 'CHF');

    $catalog = TariffCatalog::query()->create([
        'key' => 'dental-'.$slug, 'name' => 'Dental', 'version' => 1,
        'valid_from' => '2026-01-01', 'status' => TariffCatalog::STATUS_ACTIVE, 'rules' => [],
    ]);

    $make = function (string $code, string $description, int $priceMinor) use ($catalog): DentalProcedure {
        $item = TariffItem::query()->create([
            'tariff_catalog_id' => $catalog->id,
            'code' => $code,
            'description' => $description,
            'unit_price_minor' => $priceMinor,
            'currency' => 'CHF',
            'vat_rate_bp' => 0,
        ]);

        return DentalProcedure::query()->create(['tariff_item_id' => $item->id, 'tooth_scoped' => true]);
    };

    return [
        'tenant' => $tenant,
        'dentist' => $dentist,
        'patient' => $patient,
        'branch' => $branch,
        'crown' => $make('D-CROWN', 'Crown', 90000),
        'prophy' => $make('D-PROPHY', 'Cleaning', 10000),
    ];
}

test('every money figure is ENGINE-supplied and TIES to the engine to the unit (delta = 0)', function () {
    $fx = tppFixture();
    $plans = app(TreatmentPlanService::class);

    $plan = $plans->create($fx['dentist'], $fx['patient'], 'Kronenversorgung 26');
    $phase = $plans->addPhase($fx['dentist'], $plan, 'Restaurative Phase');
    $plans->addItem($fx['dentist'], $plan, $phase, $fx['crown'], '26', null);
    $plans->addItem($fx['dentist'], $plan, $phase, $fx['prophy'], '11', null);
    $plan = $plans->propose($fx['dentist'], $plan);

    // The engine's own figures, read straight from the snapshotted items.
    tppCtx()->set($fx['tenant']);
    $engineTotal = $plan->items()->get()->sum(fn ($i): int => $plans->itemEstimate($i));
    expect($engineTotal)->toBe(100000); // 90000 + 10000, integer minor units

    tppCtx()->forget();
    $this->actingAs($fx['dentist'])
        ->get(route('dental.plans', $fx['patient']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dental/TreatmentPlans')
            ->where('plans.0.total_minor', $engineTotal)
            ->where('plans.0.phases.0.total_minor', $engineTotal)
            // The DISPLAY string is produced server-side from the same integer.
            ->where('plans.0.total', "CHF 1'000.00")
            ->where('plans.0.phases.0.items.0.estimate', 'CHF 900.00')
            ->where('plans.0.phases.0.items.1.estimate', 'CHF 100.00')
            // ...and the parts tie to the whole, to the unit.
            ->where('plans.0.phases.0.items', function ($items) use ($engineTotal) {
                $sum = collect($items)->sum('estimate_minor');
                expect($sum - $engineTotal)->toBe(0, 'the item estimates do not tie to the plan total');

                return true;
            }));
});

test('"N of M billed" is the REAL charge raised by performing, not the estimate relabelled', function () {
    $fx = tppFixture();
    $plans = app(TreatmentPlanService::class);

    $plan = $plans->create($fx['dentist'], $fx['patient'], 'Plan');
    $phase = $plans->addPhase($fx['dentist'], $plan, 'Phase 1');
    $item = $plans->addItem($fx['dentist'], $plan, $phase, $fx['crown'], '26', null);
    $plans->addItem($fx['dentist'], $plan, $phase, $fx['prophy'], '11', null);
    $plan = $plans->accept($fx['dentist'], $plans->propose($fx['dentist'], $plan));

    // Nothing performed yet: billed is zero, and it is NOT the estimate.
    tppCtx()->forget();
    $this->actingAs($fx['dentist'])
        ->get(route('dental.plans', $fx['patient']->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('plans.0.billed_minor', 0)
            ->where('plans.0.billed', 'CHF 0.00')
            ->where('plans.0.total_minor', 100000));

    // Perform the crown — this captures a REAL charge through the billing engine.
    tppCtx()->forget();
    $this->actingAs($fx['dentist'])
        ->post(route('dental.plan-items.perform', $item->id), ['branch_id' => $fx['branch']->id])
        ->assertRedirect();

    tppCtx()->set($fx['tenant']);
    $charge = Charge::query()->latest('id')->firstOrFail();
    expect($charge->line_total_minor)->toBe(90000);

    // Billed now equals the CHARGE's engine total, and ties to it exactly.
    tppCtx()->forget();
    $this->actingAs($fx['dentist'])
        ->get(route('dental.plans', $fx['patient']->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('plans.0.billed_minor', 90000)
            ->where('plans.0.billed', 'CHF 900.00')
            // Still 1,000 planned — the plan estimate did not move because money was charged.
            ->where('plans.0.total_minor', 100000)
            ->where('plans.0.phases.0.items.0.billed_minor', 90000)
            // The un-performed item has NO billed figure at all — not a fabricated zero.
            ->where('plans.0.phases.0.items.1.billed_minor', null)
            ->where('plans.0.phases.0.items.1.billed', null));

    tppCtx()->set($fx['tenant']);
    $billed = (int) Charge::query()->where('status', '!=', Charge::STATUS_CANCELLED)->sum('line_total_minor');
    expect($billed - 90000)->toBe(0, 'the displayed billed figure does not tie to the ledger');
});

test('the treatment-plan surface performs NO money arithmetic — the page only prints engine strings', function () {
    $page = (string) file_get_contents(base_path('resources/js/pages/Dental/TreatmentPlans.vue'));
    $card = (string) file_get_contents(base_path('resources/js/Components/Dental/ProcedureCard.vue'));

    foreach (['TreatmentPlans.vue' => $page, 'ProcedureCard.vue' => $card] as $name => $code) {
        // The adversarial grep: any way a page could re-derive money.
        foreach (['/ 100', '/100', '.toFixed', '.reduce(', 'parseFloat', 'Number(', 'Math.'] as $arithmetic) {
            expect(str_contains($code, $arithmetic))->toBeFalse("{$name} performs money arithmetic: '{$arithmetic}'");
        }
        expect(preg_match('~_minor\s*[-+*/]~', $code))->toBe(0, "{$name} does arithmetic on minor units");
        expect(preg_match('~\bsum\s*\(~', $code))->toBe(0, "{$name} sums money");
        // No percentage or ratio of billed-to-planned.
        expect(preg_match('~billed[^\n]*[/%][^\n]*total|total[^\n]*[/%][^\n]*billed~i', $code))
            ->toBe(0, "{$name} derives a ratio between billed and planned");
    }

    // The page-side money() helper is gone: the server formats.
    expect(preg_match('~function money\s*\(~', $page))->toBe(0, 'the page still formats money itself');
});

test('NO agent drafts a treatment plan — the affordance is absent, not auto-applied', function () {
    /*
     * The mock shows an agent proposing and pricing a treatment sequence. The repo has ten
     * agent tools and NONE of them touches treatment plans, so P4 omitted the affordance
     * rather than inventing a tool. This test pins the absence at both ends.
     */
    $toolFiles = glob(base_path('app/AiCore/Tools/*.php'));
    expect($toolFiles)->not->toBeEmpty();

    foreach ($toolFiles as $file) {
        $source = strtolower((string) file_get_contents($file));
        foreach (['treatment_plan', 'treatmentplan', 'dental'] as $needle) {
            expect(str_contains($source, $needle))
                ->toBeFalse('an agent tool now touches treatment plans ('.basename($file).') — it must draft into the ApprovalQueue and never auto-apply');
        }
    }

    // And the page offers no agent affordance either.
    $page = strtolower((string) file_get_contents(base_path('resources/js/pages/Dental/TreatmentPlans.vue')));
    foreach (['agent', 'suggest', 'autofill', 'auto-apply', 'drafted for you'] as $affordance) {
        expect(str_contains($page, $affordance))->toBeFalse("the plan page offers an agent affordance: '{$affordance}'");
    }
});

test('THE RE-ASSERTION: no pathway, prognosis, recommendation, urgency or priority — and no clinical styling ramp', function () {
    $fx = tppFixture();
    $plans = app(TreatmentPlanService::class);
    $plan = $plans->create($fx['dentist'], $fx['patient'], 'Plan');
    $phase = $plans->addPhase($fx['dentist'], $plan, 'Phase 1');
    $plans->addItem($fx['dentist'], $plan, $phase, $fx['crown'], '26', null);
    $plans->propose($fx['dentist'], $plan);

    $forbidden = [
        'pathway', 'prognosis', 'recommend', 'recommended', 'recommendation', 'urgency',
        'priority', 'score', 'confidence', 'severity', 'risk', 'grade', 'stage', 'suggested',
        'proposed_by_agent', 'auto_selected', 'ranking', 'rank',
    ];

    $assertClean = function (array $data) use (&$assertClean, $forbidden): void {
        foreach ($data as $key => $value) {
            expect(in_array(strtolower((string) $key), $forbidden, true))
                ->toBeFalse("judgment key '{$key}' leaked into the treatment-plan payload");
            if (is_array($value)) {
                $assertClean($value);
            }
        }
    };

    tppCtx()->forget();
    $response = $this->actingAs($fx['dentist'])->get(route('dental.plans', $fx['patient']->id))->assertOk();
    $assertClean($response->viewData('page')['props']);

    // The components, comment-stripped (their comments name what they refuse to build).
    $strip = function (string $source): string {
        $source = preg_replace('~/\*.*?\*/~s', ' ', $source) ?? $source;
        $source = preg_replace('~<!--.*?-->~s', ' ', $source) ?? $source;

        return strtolower(preg_replace('~^\s*//.*$~m', ' ', $source) ?? $source);
    };

    $sources = [
        'TreatmentPlans.vue' => $strip((string) file_get_contents(base_path('resources/js/pages/Dental/TreatmentPlans.vue'))),
        'ProcedureCard.vue' => $strip((string) file_get_contents(base_path('resources/js/Components/Dental/ProcedureCard.vue'))),
    ];

    foreach ($sources as $name => $code) {
        foreach (['pathway', 'prognosis', 'recommend', 'urgency', 'confidence', 'severity'] as $token) {
            expect(preg_match('~\b'.preg_quote($token, '~').'\b~', $code))->toBe(0, "'{$token}' appears in {$name}");
        }
        $squashed = preg_replace('~[^a-z0-9]~', '', $code) ?? $code;
        foreach (['recommendedpathway', 'treatmentpriority', 'urgencyscore', 'severityband', 'autoselected'] as $compound) {
            expect(str_contains($squashed, $compound))->toBeFalse("'{$compound}' appears in {$name}");
        }

        /*
         * The D-169 styling rule: a ramp needs no judgment word, so no class/style binding may
         * be keyed to a clinical value or a numeric comparison. `done` is a LIFECYCLE fact the
         * caller passes (the item was performed), not a clinical grade, so it is permitted.
         */
        preg_match_all('~:(?:class|style)="([^"]*)"~', $code, $bindings);
        foreach ($bindings[1] ?? [] as $binding) {
            foreach (['severity', 'urgency', 'priority', 'score', 'risk', 'tooth', 'estimate', '_minor'] as $needle) {
                expect(str_contains($binding, $needle))
                    ->toBeFalse("{$name} styles from a clinical or money value: {$binding}");
            }
            expect(preg_match('~[<>]=?\s*\d~', $binding))
                ->toBe(0, "{$name} styles by comparing against a threshold: {$binding}");
        }
    }
});
