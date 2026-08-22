<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AiCore\Services\AutonomyPolicy;
use Modules\Clinical\Models\Recall;
use Modules\Clinical\Models\RecallRule;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientAccessReport;
use Modules\Patients\Services\PatientService;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * PC.P7 — the recall due list. Final core gate of the PC chain.
 *
 * `ReferralRecallTest` already covers the recall engine and the lifecycle and is NOT touched.
 * What is pinned here is the worklist and its fence:
 *
 *  1. THE ORDER IS A DATE SORT. Rows come back `due_on` ascending, so the longest overdue leads —
 *     because of the calendar, not a score. There is no priority column, none is computed, and the
 *     order is proven to follow the DATE rather than any other field.
 *  2. THE INTERVAL IS A PLAIN CALENDAR FACT and nothing is tinted or banded by it (D-169). The
 *     fixture spans roughly -200 to +120 days precisely because that spread is what tempts a
 *     severity band, and the absence assertions scan it (D-174).
 *  3. TRANSITIONS GO THROUGH `RecallService` — the controller writes no status.
 *  4. NOTHING CONTACTS A PATIENT. The draft tool's ceiling is SUGGEST, so the runtime can only
 *     propose to the capped approval queue; no auto-send exists and no ceiling is raised.
 */

function rwlCtx(): TenantContext
{
    return app(TenantContext::class);
}

function rwlUser(Tenant $tenant, string $role): User
{
    rwlCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
    ]);

    return $user;
}

/**
 * A REPRESENTATIVE SPREAD: long overdue → due today → due far out, across every real status.
 *
 * @return array{tenant: Tenant, clinician: User, patients: array<int, Patient>, rules: array<string, RecallRule>, recalls: array<string, Recall>}
 */
function rwlFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Clinic', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    rwlCtx()->set($tenant);

    $clinician = rwlUser($tenant, 'doctor');

    $patients = [];
    foreach (['Elisabeth Vogt', 'Margrit Stalder', 'Beat Ochsner', 'Tobias Frei'] as $i => $name) {
        [$first, $last] = explode(' ', $name);
        $patients[] = app(PatientService::class)->create([
            'first_name' => $first, 'last_name' => $last,
            'date_of_birth' => '19'.(50 + $i * 5).'-04-0'.($i + 1), 'sex' => 'female',
        ]);
    }

    $rules = [];
    foreach ([['Perio SPT', 3], ['Hygiene', 6], ['Check-up', 6]] as [$name, $months]) {
        $rules[$name] = RecallRule::query()->create([
            'name' => $name,
            'criteria' => ['type' => 'interval'],
            'interval_months' => $months,
            'active' => true,
        ]);
    }

    $make = function (string $ruleName, int $offsetDays, string $status, int $patientIndex) use ($rules, $patients): Recall {
        return Recall::query()->create([
            'patient_id' => $patients[$patientIndex]->id,
            'rule_id' => $rules[$ruleName]->id,
            'due_on' => now()->startOfDay()->addDays($offsetDays)->toDateString(),
            'status' => $status,
        ]);
    };

    // The spread that would tempt a band: -200 … +120, every real status represented.
    $recalls = [
        'longOverdue' => $make('Perio SPT', -200, Recall::STATUS_DUE, 0),
        'overdue' => $make('Perio SPT', -14, Recall::STATUS_DUE, 1),
        'justOverdue' => $make('Hygiene', -3, Recall::STATUS_CONTACTED, 2),
        'dueToday' => $make('Check-up', 0, Recall::STATUS_DUE, 3),
        'dueSoon' => $make('Check-up', 4, Recall::STATUS_BOOKED, 0),
        'dueFarOut' => $make('Hygiene', 120, Recall::STATUS_DUE, 1),
        'completed' => $make('Check-up', -300, Recall::STATUS_COMPLETED, 2),
        'dismissed' => $make('Hygiene', -280, Recall::STATUS_DISMISSED, 3),
    ];

    return compact('tenant', 'clinician', 'patients', 'rules', 'recalls');
}

/** Strip comments AND the on-screen statements OF the absence, as previous gates established. */
function rwlStrip(string $source): string
{
    $source = preg_replace('~/\*.*?\*/~s', ' ', $source) ?? $source;
    $source = preg_replace('~<!--.*?-->~s', ' ', $source) ?? $source;
    $source = preg_replace("~t\('clinical\.recalls\.(scope|order)[A-Za-z]*'\)~", ' ', $source) ?? $source;

    return strtolower(preg_replace('~(^|\s)//[^\n]*~m', '$1 ', $source) ?? $source);
}

test('the worklist lists real recalls ordered by the RECORDED DUE DATE, ascending', function () {
    $fx = rwlFixture();

    rwlCtx()->forget();
    $this->actingAs($fx['clinician'])
        ->get(route('clinical.recalls'))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $page->component('Clinical/Recalls');
            $rows = collect($page->toArray()['props']['recalls']);

            // POSITIVE CONTROL: a non-empty payload spanning the full spread (D-174).
            expect($rows)->toHaveCount(8);
            $intervals = $rows->pluck('due_in_days')->all();
            expect(min($intervals))->toBeLessThan(-100)
                ->and(max($intervals))->toBeGreaterThan(100);

            /*
             * THE ORDER FOLLOWS THE DATE. Sorting the payload by `due_on` reproduces the exact
             * order it arrived in — so the sequence is explained by the recorded date and needs no
             * other field. The longest overdue leads as a CONSEQUENCE of that, not a ranking.
             */
            $dates = $rows->pluck('due_on')->all();
            $sorted = $dates;
            sort($sorted);
            expect($dates)->toBe($sorted);
            expect($rows->first()['due_in_days'])->toBeLessThan($rows->last()['due_in_days']);

            // Every real status is represented and shown as recorded.
            expect($rows->pluck('status')->unique()->sort()->values()->all())
                ->toBe(['booked', 'completed', 'contacted', 'dismissed', 'due']);

            // No row carries a priority/urgency/rank of any kind.
            foreach ($rows as $row) {
                foreach (['priority', 'urgency', 'rank', 'score', 'severity', 'risk', 'needs_review'] as $key) {
                    expect(array_key_exists($key, $row))->toBeFalse("a recall row carries '{$key}'");
                }
            }

            return true;
        });
});

test('an empty worklist says so honestly', function () {
    $fx = rwlFixture();

    rwlCtx()->forget();
    $this->actingAs($fx['clinician'])
        ->get(route('clinical.recalls', ['status' => Recall::STATUS_BOOKED, 'within_days' => '0']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('recalls', []));

    $en = json_decode((string) file_get_contents(base_path('resources/js/lang/en.json')), true);
    expect($en['clinical']['recalls']['empty'])->toContain('No recalls match');
});

test('filters narrow on REAL attributes only — status, the tenant own rules, and a date window', function () {
    $fx = rwlFixture();

    rwlCtx()->forget();
    $this->actingAs($fx['clinician'])
        ->get(route('clinical.recalls', ['status' => Recall::STATUS_DUE]))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $rows = collect($page->toArray()['props']['recalls']);
            expect($rows)->not->toBeEmpty();
            expect($rows->pluck('status')->unique()->all())->toBe(['due']);

            return true;
        });

    rwlCtx()->forget();
    $this->actingAs($fx['clinician'])
        ->get(route('clinical.recalls', ['rule_id' => $fx['rules']['Perio SPT']->id]))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $rows = collect($page->toArray()['props']['recalls']);
            expect($rows)->not->toBeEmpty();
            expect($rows->pluck('rule_name')->unique()->all())->toBe(['Perio SPT']);

            return true;
        });

    // The date window is a plain calendar bound: "due within N days" includes everything overdue.
    rwlCtx()->forget();
    $this->actingAs($fx['clinician'])
        ->get(route('clinical.recalls', ['within_days' => '0']))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $rows = collect($page->toArray()['props']['recalls']);
            expect($rows)->not->toBeEmpty();
            foreach ($rows as $row) {
                expect($row['due_in_days'])->toBeLessThanOrEqual(0);
            }

            return true;
        });

    // The filter options come from the tenant's REAL rules, not a hardcoded taxonomy.
    rwlCtx()->forget();
    $this->actingAs($fx['clinician'])
        ->get(route('clinical.recalls'))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $names = collect($page->toArray()['props']['rules'])->pluck('name')->sort()->values()->all();
            expect($names)->toBe(['Check-up', 'Hygiene', 'Perio SPT']);

            return true;
        });
});

test('transitions go through the EXISTING service — the page writes no status', function () {
    $fx = rwlFixture();
    $recall = $fx['recalls']['longOverdue'];

    rwlCtx()->forget();
    $this->actingAs($fx['clinician'])
        ->post(route('clinical.recalls.transition', $recall->id), ['status' => Recall::STATUS_CONTACTED])
        ->assertRedirect();

    rwlCtx()->set($fx['tenant']);
    expect(Recall::query()->whereKey($recall->id)->firstOrFail()->status)->toBe(Recall::STATUS_CONTACTED);

    // The SERVICE's legal graph still bites through the new route: completed is terminal.
    $completed = $fx['recalls']['completed'];
    rwlCtx()->forget();
    $this->actingAs($fx['clinician'])
        ->post(route('clinical.recalls.transition', $completed->id), ['status' => Recall::STATUS_BOOKED])
        ->assertStatus(500);

    rwlCtx()->set($fx['tenant']);
    expect(Recall::query()->whereKey($completed->id)->firstOrFail()->status)->toBe(Recall::STATUS_COMPLETED);

    // THE CONTROLLER CANNOT WRITE A STATE.
    $controller = rwlStrip((string) file_get_contents(base_path('Modules/Clinical/src/Http/Controllers/RecallWorklistController.php')));
    expect(strlen(trim($controller)))->toBeGreaterThan(400);
    foreach (['->save(', '->update(', 'forcefill(', '->delete(', 'db::table', 'db::statement'] as $write) {
        expect(str_contains($controller, $write))->toBeFalse("the worklist writes state directly: '{$write}'");
    }
    expect($controller)->toContain('recalls->transition(');

    // Completing a recall books NOTHING — no scheduling path is touched from this screen.
    foreach (['appointment', 'slotfinder', 'lockresource', 'assertnooverlap', 'booking'] as $scheduling) {
        expect(str_contains($controller, $scheduling))->toBeFalse("the worklist reaches scheduling: '{$scheduling}'");
    }
});

test('the disclosure is audited once per patient shown, through the existing path', function () {
    $fx = rwlFixture();

    rwlCtx()->forget();
    $this->actingAs($fx['clinician'])->get(route('clinical.recalls'))->assertOk();

    rwlCtx()->set($fx['tenant']);

    /*
     * ONE row per PATIENT DISCLOSED. This worklist shows several patients at once, so a single
     * row for the whole render would leave most of those patients' access logs (PC.P5) silent
     * about a real disclosure of their record.
     */
    foreach ($fx['patients'] as $patient) {
        $rows = app(PatientAccessReport::class)->forPatientNewestFirst($patient);
        $worklist = $rows->filter(fn (object $row): bool => str_contains((string) $row->context, 'recall_worklist'));
        expect($worklist)->toHaveCount(1, 'patient '.$patient->mrn.' has '.$worklist->count().' worklist disclosure rows');
    }

    // ONE audit call site: no second mechanism was introduced.
    $controller = rwlStrip((string) file_get_contents(base_path('Modules/Clinical/src/Http/Controllers/RecallWorklistController.php')));
    expect(substr_count($controller, 'auditread('))->toBe(1);
    expect(str_contains($controller, 'audit::record'))->toBeFalse('a second audit path was introduced');
});

test('NOTHING auto-contacts a patient: the draft tool is capped at SUGGEST and only proposes', function () {
    /*
     * The tool genuinely exists, so it is wired — but its ceiling is what makes wiring it safe.
     * POSITIVE CONTROL: the tool file really resolved and really is the recall tool.
     */
    $toolPath = base_path('app/AiCore/Tools/DraftRecallMessageTool.php');
    expect(file_exists($toolPath))->toBeTrue();
    $tool = (string) file_get_contents($toolPath);
    expect($tool)->toContain("key: 'clinical.draft_recall_message'")
        ->and($tool)->toContain('autonomyCeiling: AutonomyPolicy::SUGGEST');

    /*
     * SUGGEST ranks BELOW auto in the policy's own ordering (`LEVELS` is the rank map the cap
     * uses), so `runTool` can only ever reach the propose branch. The levels are STRINGS —
     * comparing them directly would compare alphabetically and prove nothing.
     */
    expect(AutonomyPolicy::LEVELS[AutonomyPolicy::SUGGEST])
        ->toBeLessThan(AutonomyPolicy::LEVELS[AutonomyPolicy::AUTO]);

    // The tool never marks anything sent and always hands off to a human.
    expect($tool)->toContain("'sent' => false")
        ->and($tool)->toContain("'human_handoff' => true")
        ->and($tool)->toContain('blocked_no_comms_consent');

    // Neither the worklist nor its page contains a send path of any kind.
    foreach ([
        base_path('Modules/Clinical/src/Http/Controllers/RecallWorklistController.php'),
        base_path('resources/js/pages/Clinical/Recalls.vue'),
    ] as $path) {
        $code = rwlStrip((string) file_get_contents($path));
        foreach (['->send(', 'notificationservice', 'mail::', 'sendmessage', 'autosend', 'auto_send', 'dispatchnow'] as $send) {
            expect(str_contains($code, $send))->toBeFalse(basename($path)." can send to a patient: '{$send}'");
        }
        // No ceiling is raised anywhere on this surface.
        foreach (['autonomypolicy::auto', 'autonomypolicy::approve', 'autoexecute'] as $raise) {
            expect(str_contains($code, $raise))->toBeFalse(basename($path)." raises an autonomy ceiling: '{$raise}'");
        }
    }

    // And the page tells the user, in words, that nothing is sent from here.
    $en = json_decode((string) file_get_contents(base_path('resources/js/lang/en.json')), true);
    expect($en['clinical']['recalls']['scopeNoAutoSend'])->toContain('Nothing is sent automatically');
    expect($en['clinical']['recalls']['scopeNoTriage'])->toContain('does not decide which recalls need a person');
});

test('the worklist is permission-gated, write-gated and fails closed', function () {
    $fx = rwlFixture('alpha');

    // billing holds neither patient.view nor note.write.
    $billing = rwlUser($fx['tenant'], 'billing');
    rwlCtx()->forget();
    $this->actingAs($billing)->get(route('clinical.recalls'))->assertForbidden();

    // reception may READ the worklist but not transition a recall (the service's note.write gate).
    $reception = rwlUser($fx['tenant'], 'reception');
    rwlCtx()->forget();
    $this->actingAs($reception)->get(route('clinical.recalls'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('actions.can_write', false));
    rwlCtx()->forget();
    $this->actingAs($reception)
        ->post(route('clinical.recalls.transition', $fx['recalls']['overdue']->id), ['status' => Recall::STATUS_CONTACTED])
        ->assertForbidden();

    rwlCtx()->set($fx['tenant']);
    expect(Recall::query()->whereKey($fx['recalls']['overdue']->id)->firstOrFail()->status)->toBe(Recall::STATUS_DUE);

    // A cross-tenant recall is simply not found, and the worklist never leaks across tenants.
    $beta = rwlFixture('beta');
    rwlCtx()->forget();
    $this->actingAs($beta['clinician'])
        ->post(route('clinical.recalls.transition', $fx['recalls']['overdue']->id), ['status' => Recall::STATUS_CONTACTED])
        ->assertNotFound();

    rwlCtx()->forget();
    $this->actingAs($beta['clinician'])
        ->get(route('clinical.recalls'))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($beta) {
            $ids = collect($page->toArray()['props']['recalls'])->pluck('id')->all();
            $betaIds = collect($beta['recalls'])->pluck('id')->all();
            expect($ids)->not->toBeEmpty();
            foreach ($ids as $id) {
                expect(in_array($id, $betaIds, true))->toBeTrue('the worklist leaked a recall from another tenant');
            }

            return true;
        });
});

test('THE FENCE: no priority, no overdue band, no risk prediction — across the full spread', function () {
    $fx = rwlFixture();

    // SCHEMA-LEVEL CONTROL: the columns simply do not exist (the PC.P6 pattern).
    expect(Schema::hasTable('recalls'))->toBeTrue()
        ->and(Schema::hasColumn('recalls', 'due_on'))->toBeTrue()
        ->and(Schema::hasColumn('recalls', 'status'))->toBeTrue();
    foreach (['priority', 'urgency', 'severity', 'risk_score', 'rank', 'score', 'no_show_risk'] as $absent) {
        expect(Schema::hasColumn('recalls', $absent))->toBeFalse("recalls grew a '{$absent}' column");
    }

    rwlCtx()->forget();
    $props = $this->actingAs($fx['clinician'])
        ->get(route('clinical.recalls'))
        ->assertOk()
        ->viewData('page')['props'];

    // POSITIVE CONTROL: the payload really spans long-overdue → due-far-out (D-174).
    $intervals = collect($props['recalls'])->pluck('due_in_days');
    expect($intervals)->toHaveCount(8)
        ->and($intervals->min())->toBeLessThan(-100)
        ->and($intervals->max())->toBeGreaterThan(100);

    $forbidden = [
        'priorityscore', 'urgencylevel', 'urgencyband', 'overdueband', 'severityband', 'riskscore',
        'noshowrisk', 'nonattendance', 'triage', 'needsreview', 'rankedby', 'autosend', 'autosent',
        'escalationlevel',
    ];

    $squashed = preg_replace('~[^a-z0-9]~', '', strtolower(json_encode($props) ?: '')) ?? '';
    expect(strlen($squashed))->toBeGreaterThan(400);
    foreach ($forbidden as $token) {
        expect(str_contains($squashed, $token))->toBeFalse("fence token '{$token}' appears in the recall payload");
    }

    // D-173 — the scan follows every path this gate created.
    foreach ([
        base_path('resources/js/pages/Clinical/Recalls.vue'),
        base_path('Modules/Clinical/src/Http/Controllers/RecallWorklistController.php'),
    ] as $path) {
        expect(file_exists($path))->toBeTrue(basename($path).' is missing — this fence would scan nothing');
        $code = rwlStrip((string) file_get_contents($path));
        expect(strlen(trim($code)))->toBeGreaterThan(400, basename($path).' stripped to almost nothing');

        $squashedFile = preg_replace('~[^a-z0-9]~', '', $code) ?? '';
        foreach ($forbidden as $token) {
            expect(str_contains($squashedFile, $token))->toBeFalse("fence token '{$token}' appears in ".basename($path));
        }

        /*
         * D-169 — THE ASSERTION THIS SCREEN EXISTS TO PASS. Nothing may be styled by how overdue a
         * row is. An overdue recall and a not-yet-due one carry identical chrome; the words and the
         * date say the rest. (Verified in a browser too: every row across -200…+120 resolved to one
         * class string and one background.)
         */
        preg_match_all('~:(?:class|style)="([^"]*)"~', $code, $bindings);
        foreach ($bindings[1] ?? [] as $binding) {
            foreach (['due_in_days', 'dueindays', 'due_on', 'overdue', 'priority', 'urgency', 'severity', 'risk'] as $needle) {
                expect(str_contains($binding, $needle))->toBeFalse(basename($path)." styles by how overdue a row is: {$binding}");
            }
            expect(preg_match('~[<>]=?\s*-?\d~', $binding))->toBe(0, basename($path)." styles by a threshold: {$binding}");
        }
    }
});
