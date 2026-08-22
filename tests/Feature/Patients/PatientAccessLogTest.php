<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Audit\Facades\Audit;
use Modules\Audit\Services\AuditService;
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
 * PC.P5 — the dedicated patient access log + the nDSG/GDPR subject-access export.
 *
 * COMPLETENESS IS THE PROPERTY UNDER TEST. A transparency screen that quietly drops a category of
 * reader is a FALSE ASSURANCE to a patient exercising a legal right — worse than no screen. So the
 * fixture is deliberately MULTI-ACTOR (D-174): a staff read, an AGENT read, a PORTAL read by the
 * patient themself, a SYSTEM read with no actor id, and an OPERATOR read. A single-actor fixture
 * would let an actor-type whitelist pass unnoticed, which is exactly the bug worth catching.
 *
 * The other properties: screen and export share ONE query (the export cannot disagree with the
 * screen), the export is itself audited, both are `audit.view` + `patient.view` gated and
 * tenant-scoped fail-closed, the append-only chain still verifies, and NOTHING here judges a read
 * — no suspicious/anomaly/risk key, and no styling keyed to actor type (D-169).
 */

function paltCtx(): TenantContext
{
    return app(TenantContext::class);
}

function paltUser(Tenant $tenant, string $role): User
{
    paltCtx()->set($tenant);
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', $role)->firstOrFail()->id,
    ]);

    return $user;
}

/**
 * A REPRESENTATIVE, MULTI-ACTOR fixture — the whole point of this suite.
 *
 * @return array{tenant: Tenant, admin: User, patient: Patient}
 */
function paltFixture(string $slug = 'alpha'): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Clinic', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    paltCtx()->set($tenant);

    // org_admin holds BOTH audit.view and patient.view — the role that fields a subject-access request.
    $admin = paltUser($tenant, 'org_admin');
    $patient = app(PatientService::class)->create([
        'first_name' => 'Nora', 'last_name' => 'Keller', 'date_of_birth' => '1988-03-14', 'sex' => 'female',
    ]);

    paltRead($patient, 'user', (string) $admin->id, 'patient_360');
    // An agent read: the AI tools write the acting user's identity with a surface naming the tool.
    paltRead($patient, 'user', (string) $admin->id, 'clinical_summary_agent');
    // The patient viewing their own record through the portal.
    paltRead($patient, 'patient', '9001', 'portal_home');
    // A system read with NO actor id — the case a naive DISTINCT count drops.
    paltRead($patient, 'service', null, 'notification_dispatch');
    // An operator (platform support) read, patient-attributed.
    paltRead($patient, 'operator', '7', 'patient_360');

    return compact('tenant', 'admin', 'patient');
}

function paltRead(Patient $patient, string $actorType, ?string $actorId, string $surface): void
{
    Audit::record([
        'action' => 'read',
        'actor_type' => $actorType,
        'actor_id' => $actorId,
        'resource_type' => 'patients',
        'resource_id' => $patient->id,
        'patient_id' => $patient->id,
        'context' => ['surface' => $surface],
    ]);
}

/** Strip comments so the scans test AFFORDANCES, not the prose documenting their absence. */
function paltStrip(string $source): string
{
    $source = preg_replace('~/\*.*?\*/~s', ' ', $source) ?? $source;
    $source = preg_replace('~<!--.*?-->~s', ' ', $source) ?? $source;

    return strtolower(preg_replace('~(^|\s)//[^\n]*~m', '$1 ', $source) ?? $source);
}

test('COMPLETENESS: the log returns a row for EVERY actor type that read the record', function () {
    $fx = paltFixture();

    paltCtx()->forget();
    $this->actingAs($fx['admin'])
        ->get(route('patients.access-log', $fx['patient']->id))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $page->component('Patients/AccessLog');

            $rows = collect($page->toArray()['props']['rows']);

            // POSITIVE CONTROL: a NON-EMPTY, genuinely multi-actor payload (D-174). Without this
            // an actor-type whitelist could pass against a single-actor fixture.
            expect($rows)->not->toBeEmpty('the access log returned no rows to inspect');

            $actorTypes = $rows->pluck('actor_type')->unique()->sort()->values()->all();

            /*
             * THE COMPLETENESS ASSERTION. Every kind of reader the fixture created must be here.
             * Excluding any one of them from the query turns this red.
             */
            // (`toContain` takes NEEDLES, not a message — a second argument would be asserted
            // as another value, which silently changes what is being checked.)
            foreach (['user', 'patient', 'service', 'operator'] as $expected) {
                expect(in_array($expected, $actorTypes, true))
                    ->toBeTrue("the log omits '{$expected}' reads — an incomplete log is a false assurance");
            }

            // The agent read is ATTRIBUTED off its recorded surface, not judged.
            $agent = $rows->firstWhere('surface', 'clinical_summary_agent');
            expect($agent)->not->toBeNull()
                ->and($agent['is_agent'])->toBeTrue();

            // A non-agent read is not marked as one.
            expect($rows->firstWhere('surface', 'portal_home')['is_agent'])->toBeFalse();

            return true;
        });
});

test('the report applies NO actor-type filter by default — completeness is structural, not a list', function () {
    $fx = paltFixture();
    paltCtx()->set($fx['tenant']);

    $report = app(PatientAccessReport::class);
    $all = $report->forPatientNewestFirst($fx['patient']);

    // POSITIVE CONTROL: the fixture really produced several actor types.
    expect($all->pluck('actor_type')->unique()->count())->toBeGreaterThanOrEqual(4);

    // Newest first, and the 360 tab's oldest-first view is the same set in reverse.
    $oldestFirst = $report->forPatient($fx['patient']);
    expect($all->count())->toBe($oldestFirst->count())
        ->and($all->first()->occurred_at)->toBe($oldestFirst->last()->occurred_at)
        ->and($all->last()->occurred_at)->toBe($oldestFirst->first()->occurred_at);

    /*
     * There is ONE query in the report class. A second `FROM audit_events` would be a second
     * source that could drift from this one — the failure this whole class is shaped to avoid.
     */
    $source = paltStrip((string) file_get_contents(base_path('Modules/Patients/src/Services/PatientAccessReport.php')));
    // Exactly three statements touch the ledger: the rows, the actor-type counts, the distinct
    // count. A fourth would be a second source that can drift from the one the export uses.
    expect(substr_count($source, 'from audit_events'))->toBe(3);
    // ...and every one of them is scoped to this tenant, this action and this patient.
    expect(substr_count($source, 'tenant_id <=> ? and action = ? and patient_id = ?'))->toBe(3);
    // ...and the row-returning path is not filtered by actor type unless the caller asked.
    expect($source)->toContain('$actortypes !== []');

    // The distinct-actor count includes a reader with NO actor id (a system read).
    expect($report->distinctActorCountFor($fx['patient']))->toBe(4);
});

test('the export contains EXACTLY the rows the screen shows, from the same query', function () {
    $fx = paltFixture();

    paltCtx()->forget();
    $screen = $this->actingAs($fx['admin'])
        ->get(route('patients.access-log', $fx['patient']->id))
        ->assertOk();

    $screenRows = collect($screen->viewData('page')['props']['rows']);
    expect($screenRows)->not->toBeEmpty();

    paltCtx()->forget();
    $csv = $this->actingAs($fx['admin'])
        ->get(route('patients.access-log.export', $fx['patient']->id))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->getContent();

    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $header = array_shift($lines);
    expect($header)->toBe('occurred_at,actor_type,actor_id,actor_name,resource_type,resource_id,surface');

    /*
     * The export ran AFTER the screen and audits itself, so it legitimately holds exactly one
     * more row than the screen did — its own. Every row the screen showed must still be there:
     * same query, same rows, nothing dropped in the file the patient actually receives.
     */
    expect(count($lines))->toBe($screenRows->count() + 1);

    $exportedAt = array_map(fn (string $line): string => trim(str_getcsv($line)[0]), $lines);
    foreach ($screenRows as $row) {
        expect(in_array($row['occurred_at'], $exportedAt, true))
            ->toBeTrue('a row shown on screen is missing from the export: '.$row['occurred_at']);
    }

    // Every actor type survives into the file — the export is no narrower than the screen.
    $exportedTypes = array_unique(array_map(fn (string $line): string => str_getcsv($line)[1], $lines));
    foreach (['user', 'patient', 'service', 'operator'] as $expected) {
        expect(in_array($expected, $exportedTypes, true))->toBeTrue("the export drops '{$expected}' reads");
    }
});

test('viewing the log and exporting it are themselves audited, through the existing path', function () {
    $fx = paltFixture();

    paltCtx()->set($fx['tenant']);
    $before = app(PatientAccessReport::class)->forPatientNewestFirst($fx['patient'])->count();
    expect($before)->toBeGreaterThan(0);

    paltCtx()->forget();
    $this->actingAs($fx['admin'])->get(route('patients.access-log', $fx['patient']->id))->assertOk();

    paltCtx()->set($fx['tenant']);
    $afterView = app(PatientAccessReport::class)->forPatientNewestFirst($fx['patient']);
    expect($afterView->count())->toBe($before + 1);
    expect(json_decode((string) $afterView->first()->context, true)['surface'])->toBe('patient_access_log');

    paltCtx()->forget();
    $this->actingAs($fx['admin'])->get(route('patients.access-log.export', $fx['patient']->id))->assertOk();

    paltCtx()->set($fx['tenant']);
    $afterExport = app(PatientAccessReport::class)->forPatientNewestFirst($fx['patient']);
    expect($afterExport->count())->toBe($before + 2);
    expect(json_decode((string) $afterExport->first()->context, true)['surface'])->toBe('patient_access_log_export');

    // ONE audit path: the controller writes through auditRead() and nowhere else.
    // Comment-stripped: the docblocks explaining the audit path must not be counted as call
    // sites (the recurring lesson — the sentence DECLARING a policy is not the code doing it).
    $controller = paltStrip((string) file_get_contents(base_path('app/Http/Controllers/PatientAccessLogController.php')));
    expect(substr_count($controller, 'auditread('))->toBe(2, 'the screen and the export write one row each — and nothing else audits here');
    expect(str_contains($controller, 'audit::record'))->toBeFalse('a second audit path was introduced');
});

test('the log and the export are audit.view + patient.view gated and fail closed across tenants', function () {
    $fx = paltFixture('alpha');

    // A doctor holds patient.view but NOT audit.view — refused on both, though Patient 360 is fine.
    $doctor = paltUser($fx['tenant'], 'doctor');
    paltCtx()->forget();
    $this->actingAs($doctor)->get(route('patients.access-log', $fx['patient']->id))->assertForbidden();
    paltCtx()->forget();
    $this->actingAs($doctor)->get(route('patients.access-log.export', $fx['patient']->id))->assertForbidden();
    // POSITIVE CONTROL: the refusal is the NEW gate, not a broken session — 360 still works.
    paltCtx()->forget();
    $this->actingAs($doctor)->get(route('patients.show', $fx['patient']->id))->assertOk();

    // A different tenant's admin cannot reach this patient at all.
    $beta = paltFixture('beta');
    paltCtx()->forget();
    $this->actingAs($beta['admin'])->get(route('patients.access-log', $fx['patient']->id))->assertNotFound();
    paltCtx()->forget();
    $this->actingAs($beta['admin'])->get(route('patients.access-log.export', $fx['patient']->id))->assertNotFound();

    // And the cross-tenant attempt disclosed nothing: beta's own log is untouched by alpha's rows.
    paltCtx()->set($beta['tenant']);
    $betaRows = app(PatientAccessReport::class)->forPatientNewestFirst($beta['patient']);
    expect($betaRows->pluck('patient_id')->unique()->all())->toBe([$beta['patient']->id]);
});

test('INTEGRITY: the chain still verifies and this gate added no mutation or delete path', function () {
    $fx = paltFixture();

    paltCtx()->forget();
    $this->actingAs($fx['admin'])->get(route('patients.access-log', $fx['patient']->id))->assertOk();
    paltCtx()->forget();
    $this->actingAs($fx['admin'])->get(route('patients.access-log.export', $fx['patient']->id))->assertOk();

    paltCtx()->set($fx['tenant']);
    $chain = app(AuditService::class)->verifyChain($fx['tenant']->id);

    // POSITIVE CONTROL: the chain actually had rows to verify (D-174).
    expect($chain['count'] ?? 0)->toBeGreaterThan(5)
        ->and($chain['ok'])->toBeTrue('the audit chain no longer verifies after this gate');

    // Neither new file can write to, update or delete an audit row.
    foreach ([
        'app/Http/Controllers/PatientAccessLogController.php',
        'Modules/Patients/src/Services/PatientAccessReport.php',
    ] as $path) {
        $code = paltStrip((string) file_get_contents(base_path($path)));
        expect(strlen(trim($code)))->toBeGreaterThan(400, basename($path).' stripped to almost nothing');

        foreach (['update ', 'delete ', 'insert ', 'truncate', '->update(', '->delete(', '->save(', 'db::statement'] as $write) {
            expect(str_contains($code, $write))->toBeFalse(basename($path)." can write to the ledger: '{$write}'");
        }
    }

    // The report reads the ledger with SELECT and nothing else; the controller never touches
    // the table at all, so there is exactly one place a query could go wrong.
    $report = paltStrip((string) file_get_contents(base_path('Modules/Patients/src/Services/PatientAccessReport.php')));
    expect($report)->toContain('db::select');
    $controller = paltStrip((string) file_get_contents(base_path('app/Http/Controllers/PatientAccessLogController.php')));
    expect(str_contains($controller, 'audit_events'))->toBeFalse('the controller queries the ledger directly instead of going through the report');
});

test('THE FENCE: the log passes no judgment on a read and tints nothing by actor type', function () {
    $fx = paltFixture();

    paltCtx()->forget();
    $response = $this->actingAs($fx['admin'])
        ->get(route('patients.access-log', $fx['patient']->id))
        ->assertOk();

    $props = $response->viewData('page')['props'];

    // POSITIVE CONTROL: a non-empty, multi-actor payload — including the operator read that a
    // system inclined to editorialise would flag first (D-174).
    expect($props['rows'])->not->toBeEmpty();
    expect(collect($props['rows'])->pluck('actor_type')->unique()->count())->toBeGreaterThanOrEqual(4);
    expect(collect($props['rows'])->pluck('actor_type'))->toContain('operator');

    $forbidden = [
        'suspicious', 'anomaly', 'anomalous', 'riskscore', 'risklevel', 'unusual', 'threat',
        'severity', 'priority', 'flagged', 'notable', 'concerning', 'breachscore', 'trustscore',
    ];

    $squashed = preg_replace('~[^a-z0-9]~', '', strtolower(json_encode($props) ?: '')) ?? '';
    expect(strlen($squashed))->toBeGreaterThan(400);
    foreach ($forbidden as $token) {
        expect(str_contains($squashed, $token))->toBeFalse("fence token '{$token}' appears in the access-log payload");
    }

    // D-173 — the scan follows every path this gate created or touched.
    $files = [
        base_path('resources/js/pages/Patients/AccessLog.vue'),
        base_path('resources/js/Components/Clinical/AccessLogRow.vue'),
        base_path('app/Http/Controllers/PatientAccessLogController.php'),
        base_path('Modules/Patients/src/Services/PatientAccessReport.php'),
    ];

    foreach ($files as $path) {
        expect(file_exists($path))->toBeTrue(basename($path).' is missing — this fence would scan nothing');
        $code = paltStrip((string) file_get_contents($path));
        $squashedFile = preg_replace('~[^a-z0-9]~', '', $code) ?? '';

        foreach ($forbidden as $token) {
            expect(str_contains($squashedFile, $token))->toBeFalse("fence token '{$token}' appears in ".basename($path));
        }

        /*
         * D-169 — NOTHING may be styled by actor type. An operator read and a receptionist read
         * must render identically; painting one as alarming is the system telling a reviewer what
         * to think about a fact it merely recorded. (Verified in a browser too: all four actor
         * types render with exactly one basis-chip style.)
         */
        preg_match_all('~:(?:class|style)="([^"]*)"~', $code, $bindings);
        foreach ($bindings[1] ?? [] as $binding) {
            /*
             * The line to draw is between SELECTION STATE and IDENTITY. Highlighting the chip
             * the reader has clicked is ordinary UI; giving 'operator' its own colour is the
             * system editorialising about a fact it merely recorded. So what is forbidden is a
             * binding that COMPARES an actor type (or the agent mark) to a value — the shape
             * every "paint the scary ones red" implementation must take.
             */
            foreach (['operator', 'suspicious', 'is_agent', 'isagent'] as $needle) {
                expect(str_contains($binding, $needle))->toBeFalse(basename($path)." styles by who read the record: {$binding}");
            }
            expect(preg_match('~actor_?type\s*[=!]==?~', $binding))
                ->toBe(0, basename($path)." gives an actor type its own appearance: {$binding}");
        }
    }

    // The page states what it cannot show rather than implying the log is exhaustive.
    $en = json_decode((string) file_get_contents(base_path('resources/js/lang/en.json')), true);
    expect($en['patients']['accessLog']['scopeOperator'])->toContain('recorded against the clinic');
    expect($en['patients']['accessLog']['scopeSelf'])->toContain('will appear here');
});
