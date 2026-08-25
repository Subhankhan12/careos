<?php

use App\Services\AgentMetricsService;
use App\Services\GovernanceLedgerExporter;
use Database\Seeders\DemoClinicSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\AiCore\Models\AiInteraction;
use Modules\Audit\Services\AuditService;
use Modules\Platform\Models\Permission;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * GOV.P5 — the governance-ledger export.
 *
 * An export is the one governance feature that produces something which LEAVES the building, so its
 * failure modes are different from every other screen in this batch:
 *
 *   - it can carry more than it should (PHI/clinical free text) — minimisation, proven by showing the
 *     SOURCE rows really do contain such content and the default file does not;
 *   - it can be truncated or edited afterwards and still look official — hence a manifest that is not
 *     optional and detects both;
 *   - it can disagree with the screen it came from — hence one definition with GOV.P1's reader;
 *   - it can leak another tenant's rows, or be taken by someone who may only read the log on screen.
 */

function lxSeed(): Tenant
{
    Storage::fake('local');
    (new DemoClinicSeeder)->run();

    $tenant = Tenant::query()->where('slug', DemoClinicSeeder::TENANT_SLUG)->firstOrFail();
    app(TenantContext::class)->set($tenant);

    return $tenant;
}

function lxUser(Tenant $tenant, string $roleKey): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id,
    ]);

    return $user;
}

/** A second tenant with its own ledger row — something that WOULD leak if scoping failed. */
function lxOtherTenantRow(): Tenant
{
    $other = Tenant::query()->create(['name' => 'Beta Clinic', 'slug' => 'beta-export', 'region' => 'eu', 'status' => 'active']);

    app(TenantContext::class)->set($other);
    AiInteraction::query()->create([
        'tenant_id' => $other->id,
        'feature' => 'comms.draft_reply',
        'agent' => 'inbox',
        'provider' => 'internal',
        'model' => 'tool-runtime',
        'model_version' => '1',
        'prompt_hash' => str_repeat('b', 64),
        'outcome' => 'proposed',
        'label' => 'BETA-ONLY-ROW',
        'metadata' => ['why' => 'BETA-TENANT-SECRET'],
        'occurred_at' => Carbon::now(),
    ]);

    return $other;
}

/** The default window used throughout: wide enough to hold GOV.P4's whole spread. */
function lxWindow(): array
{
    return [Carbon::today()->subDays(29), Carbon::today()];
}

test('the fixture has what each property needs: a spread, a second tenant, and PHI-bearing rows', function () {
    $tenant = lxSeed();
    $other = lxOtherTenantRow();
    app(TenantContext::class)->set($tenant);

    // GOV.P4's spread, across outcomes and dates.
    [$from, $to] = lxWindow();
    $rows = AiInteraction::query()->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get();
    expect($rows->pluck('outcome')->unique()->count())->toBeGreaterThanOrEqual(4)
        ->and($rows->pluck('occurred_at')->map(fn ($d) => $d->toDateString())->unique()->count())->toBeGreaterThanOrEqual(3);

    /*
     * THE POSITIVE CONTROL FOR MINIMISATION (D-174). The source rows genuinely carry caller-supplied
     * prose — here the German "why" the seeder wrote. If they did not, "the export omits it" would be
     * a statement about nothing.
     */
    $withFreeText = $rows->filter(fn (AiInteraction $r): bool => ! empty($r->metadata));
    expect($withFreeText)->not->toBeEmpty();

    // At least one of them carries a caller-written SENTENCE (the seeder's German "why"), which is
    // the prose the minimisation test proves the default export leaves behind. Asserted across the
    // set rather than on the first row: the KB embedding sync also writes metadata, so "first" is
    // not stable.
    expect($withFreeText->contains(fn (AiInteraction $r): bool => str_contains((string) json_encode($r->metadata), 'why')))
        ->toBeTrue('no ledger row carries caller-written prose — the minimisation test would prove nothing');

    // And the second tenant really has a row that would show up if scoping failed.
    app(TenantContext::class)->set($other);
    expect(AiInteraction::query()->where('label', 'BETA-ONLY-ROW')->count())->toBe(1);
});

test('MINIMISATION — the default export carries no free text, though the source rows do', function () {
    $tenant = lxSeed();
    $admin = lxUser($tenant, 'org_admin');
    [$from, $to] = lxWindow();

    // The prose that exists in the database and must NOT leave in the default file.
    $secret = 'auf der Warteliste steht eine passende Anfrage';
    expect(
        AiInteraction::query()->get()
            ->contains(fn (AiInteraction $r): bool => str_contains((string) json_encode($r->metadata), 'Warteliste'))
    )->toBeTrue('the fixture no longer carries the prose this test is about');

    $export = app(GovernanceLedgerExporter::class)->export($admin, $from, $to);

    // POSITIVE CONTROL: the file is NOT empty — it has real rows to have omitted something from.
    expect($export['rowCount'])->toBeGreaterThan(0);
    expect($export['payload'])->toContain('comms.draft_reply');

    // The free-text columns are absent from the header, and the prose is absent from the body.
    expect($export['payload'])->not->toContain('metadata')
        ->and($export['payload'])->not->toContain('error_message')
        ->and($export['payload'])->not->toContain($secret)
        ->and($export['payload'])->not->toContain('Warteliste');

    // The manifest says so too, so a reader need not open the file to know.
    expect($export['manifest']['contains_free_text'])->toBeFalse()
        ->and($export['manifest']['opt_ins'])->toBe([])
        ->and($export['manifest']['columns'])->toBe(GovernanceLedgerExporter::DEFAULT_COLUMNS);

    // ...and no patient identifier travels either: the ledger has no patient column, and none of the
    // exported values is a patient id.
    $patientIds = DB::table('patients')->pluck('id');
    foreach ($patientIds as $id) {
        expect(str_contains($export['payload'], (string) $id))->toBeFalse('a patient id reached the export');
    }
});

test('the opt-in adds the free text — and only for someone with the higher permission', function () {
    $tenant = lxSeed();
    $admin = lxUser($tenant, 'org_admin');
    [$from, $to] = lxWindow();

    $with = app(GovernanceLedgerExporter::class)
        ->export($admin, $from, $to, [GovernanceLedgerExporter::OPT_IN_FREE_TEXT]);

    // The opt-in genuinely changes the file — which is what makes the default's omission meaningful.
    expect($with['payload'])->toContain('metadata')
        ->and($with['payload'])->toContain('Warteliste')
        ->and($with['manifest']['contains_free_text'])->toBeTrue()
        ->and($with['manifest']['opt_ins'])->toBe([GovernanceLedgerExporter::OPT_IN_FREE_TEXT]);

    /*
     * D-182 at the ROUTE: the same request succeeds for an admin and is REFUSED for a records
     * officer who holds audit.view (and, in this build, audit.export is not theirs either) — so the
     * refusal is the permission and not a broken request.
     */
    $params = ['from' => $from->toDateString(), 'to' => $to->toDateString()];

    $this->actingAs($admin)->get(route('governance.ledger.export', $params + ['include_free_text' => 1]))->assertOk();

    $records = lxUser($tenant, 'him_records');
    $this->actingAs($records)->get(route('governance.ledger.export', $params))->assertForbidden();
});

test('the free-text gate is its OWN gate — pinned where the export gate cannot answer first', function () {
    $tenant = lxSeed();
    [$from, $to] = lxWindow();

    /*
     * D-183, and a gap a mutation found: deleting the free-text permission check left the suite green,
     * because the only SEEDED role holding `audit.export` (org_admin) also holds `admin.manage` — so
     * the outer gate always answered first and the inner one was never the deciding factor.
     *
     * The two permissions are separable by design even though today's catalogue grants them together,
     * so this builds exactly that user: a role with `audit.export` and nothing else. Now the inner
     * gate is the only thing standing between them and the free text, and removing it turns this red.
     */
    $role = Role::query()->create(['key' => 'ledger_auditor', 'name' => 'Ledger auditor', 'is_system' => false]);
    $role->permissions()->attach(Permission::query()->where('key', 'audit.export')->firstOrFail()->id);

    $auditor = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $auditor->id, 'role_id' => $role->id]);

    $params = ['from' => $from->toDateString(), 'to' => $to->toDateString()];

    // POSITIVE CONTROL (D-182): they CAN export — so the refusal below is the free-text gate alone,
    // not a lack of access to the feature.
    $this->actingAs($auditor)->get(route('governance.ledger.export', $params))->assertOk();

    // ...and asking for the free text is refused outright, never silently downgraded to a file that
    // looks like what was asked for and is not.
    $this->actingAs($auditor)
        ->get(route('governance.ledger.export', $params + ['include_free_text' => 1]))
        ->assertForbidden();

    // The refused attempt produced no export row, so a refusal cannot be mistaken for an export.
    expect(
        DB::table('audit_events')
            ->where('action', 'governance.ledger_exported')
            ->get()
            ->filter(fn ($row): bool => json_decode((string) $row->context, true)['opt_ins'] !== [])
            ->count()
    )->toBe(0);
});
test('the export set is the SAME set the dashboard shows for that window', function () {
    $tenant = lxSeed();
    $admin = lxUser($tenant, 'org_admin');
    [$from, $to] = lxWindow();

    $exportRows = app(GovernanceLedgerExporter::class)->rows($from, $to);
    $dashboard = app(AgentMetricsService::class)->window($from, $to);

    // One definition: the file and the screen count the same ledger rows for the same window.
    expect(count($exportRows))->toBe($dashboard['ledgerTotal'], 'the export and the dashboard disagree about the window');

    // Narrowing the window narrows the file the same way it narrows the screen.
    $narrow = app(GovernanceLedgerExporter::class)->rows(Carbon::today()->subDays(6), Carbon::today());
    $narrowDashboard = app(AgentMetricsService::class)->window(Carbon::today()->subDays(6), Carbon::today());
    expect(count($narrow))->toBe($narrowDashboard['ledgerTotal'])
        ->and(count($narrow))->toBeLessThan(count($exportRows));
});

test('THE MANIFEST is always emitted, and detects a truncated or altered payload', function () {
    $tenant = lxSeed();
    $admin = lxUser($tenant, 'org_admin');
    [$from, $to] = lxWindow();

    $export = app(GovernanceLedgerExporter::class)->export($admin, $from, $to);
    $manifest = $export['manifest'];

    // It records everything a recipient needs to judge the file.
    expect($manifest)->toHaveKeys([
        'generated_at', 'generated_by', 'tenant_id', 'window', 'filters', 'row_count',
        'columns', 'opt_ins', 'contains_free_text', 'payload_sha256', 'payload_bytes',
        'audit_chain', 'self_audit',
    ]);
    expect($manifest['generated_by'])->toBe((string) $admin->getKey())
        ->and($manifest['row_count'])->toBe($export['rowCount'])
        ->and($manifest['audit_chain']['ok'])->toBeTrue();

    // The hash matches the payload as issued — the baseline the checks below move away from.
    expect(hash('sha256', $export['payload']))->toBe($manifest['payload_sha256']);

    /*
     * TRUNCATION — drop the last row. This is the attack the manifest exists for: a file that still
     * parses, still looks official, and is quietly missing an inconvenient entry.
     */
    $lines = explode("\n", trim($export['payload']));
    array_pop($lines);
    $truncated = implode("\n", $lines)."\n";
    expect(hash('sha256', $truncated))->not->toBe($manifest['payload_sha256'])
        ->and(strlen($truncated))->not->toBe($manifest['payload_bytes']);

    // ALTERATION — change one character.
    $altered = str_replace('executed', 'rejected', $export['payload']);
    expect($altered)->not->toBe($export['payload']);
    expect(hash('sha256', $altered))->not->toBe($manifest['payload_sha256']);
});

test('the export is SELF-AUDITED — exactly one row, on the existing path', function () {
    $tenant = lxSeed();
    $admin = lxUser($tenant, 'org_admin');
    [$from, $to] = lxWindow();

    $before = DB::table('audit_events')->where('action', 'governance.ledger_exported')->count();
    expect($before)->toBe(0);

    $this->actingAs($admin)->get(route('governance.ledger.export', [
        'from' => $from->toDateString(),
        'to' => $to->toDateString(),
    ]))->assertOk();

    $rows = DB::table('audit_events')->where('action', 'governance.ledger_exported')->get();
    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    $context = json_decode((string) $row->context, true);

    expect($row->actor_id)->toBe((string) $admin->getKey())
        ->and($row->resource_type)->toBe('ai_interaction_ledger')
        ->and($context['row_count'])->toBeGreaterThan(0)
        ->and($context['opt_ins'])->toBe([])
        ->and($context['payload_sha256'])->not->toBeEmpty();

    // The opt-in is recorded when used — an export that took free text says so in the trail.
    $this->actingAs($admin)->get(route('governance.ledger.export', [
        'from' => $from->toDateString(), 'to' => $to->toDateString(), 'include_free_text' => 1,
    ]))->assertOk();

    $latest = DB::table('audit_events')->where('action', 'governance.ledger_exported')
        ->orderByDesc('occurred_at')->first();
    expect(json_decode((string) $latest->context, true)['opt_ins'])->toBe(['free_text']);
});

test('TENANT ISOLATION — another tenant\'s ledger row can never appear', function () {
    $tenant = lxSeed();
    $other = lxOtherTenantRow();
    app(TenantContext::class)->set($tenant);

    $admin = lxUser($tenant, 'org_admin');
    [$from, $to] = lxWindow();

    /*
     * D-183 — pinned at the EXPORTER, called directly, so no route middleware is what refuses. The
     * beta row is inside the window and would be picked up by an unscoped query; only the model's
     * own global scope keeps it out.
     */
    $export = app(GovernanceLedgerExporter::class)->export($admin, $from, $to, [GovernanceLedgerExporter::OPT_IN_FREE_TEXT]);

    // POSITIVE CONTROL: the beta row exists, is in the window, and carries a distinctive marker.
    app(TenantContext::class)->set($other);
    $beta = AiInteraction::query()->where('label', 'BETA-ONLY-ROW')->firstOrFail();
    expect($beta->occurred_at->between($from->copy()->startOfDay(), $to->copy()->endOfDay()))->toBeTrue();
    app(TenantContext::class)->set($tenant);

    // Even with the free-text opt-in on — the widest the export can ever be — nothing of beta's shows.
    expect($export['payload'])->not->toContain('BETA-ONLY-ROW')
        ->and($export['payload'])->not->toContain('BETA-TENANT-SECRET')
        ->and($export['payload'])->not->toContain($beta->id);

    expect($export['manifest']['tenant_id'])->toBe($tenant->id);

    // ...and over HTTP too.
    $response = $this->actingAs($admin)->get(route('governance.ledger.export', [
        'from' => $from->toDateString(), 'to' => $to->toDateString(),
    ]))->assertOk();
    expect($response->streamedContent() ?: '')->not->toContain('BETA-ONLY-ROW');
});

test('exporting does not mutate the ledger — the chain still verifies', function () {
    $tenant = lxSeed();
    $admin = lxUser($tenant, 'org_admin');
    [$from, $to] = lxWindow();

    $before = AiInteraction::query()->count();
    expect(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();

    $this->actingAs($admin)->get(route('governance.ledger.export', [
        'from' => $from->toDateString(), 'to' => $to->toDateString(),
    ]))->assertOk();

    /*
     * The ledger is untouched (its rows are append-only at the database anyway) and the audit chain
     * still verifies AFTER the export's own row was appended — an export must not be able to break
     * the thing it is evidence of.
     */
    expect(AiInteraction::query()->count())->toBe($before)
        ->and(app(AuditService::class)->verifyChain($tenant->id)['ok'])->toBeTrue();
});

test('the export route is audit.export gated — narrower than reading the dashboard', function () {
    $tenant = lxSeed();
    [$from, $to] = lxWindow();
    $params = ['from' => $from->toDateString(), 'to' => $to->toDateString()];

    /*
     * The gate's whole point: him_records may READ the audit log on the dashboard and may NOT take
     * it out as a file. Both halves are asserted, so "narrower" is demonstrated rather than claimed.
     */
    $records = lxUser($tenant, 'him_records');
    $this->actingAs($records)->get(route('governance.dashboard'))->assertOk();
    $this->actingAs($records)->get(route('governance.ledger.export', $params))->assertForbidden();

    // POSITIVE CONTROL: the org admin holds audit.export and gets the file.
    $this->actingAs(lxUser($tenant, 'org_admin'))->get(route('governance.ledger.export', $params))->assertOk();

    // A user with no role at all is refused too.
    $nobody = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    $this->actingAs($nobody)->get(route('governance.ledger.export', $params))->assertForbidden();
});

test('the omissions are RENDERED, not just translated (the GOV.P3 lesson)', function () {
    $tenant = lxSeed();
    $admin = lxUser($tenant, 'org_admin');

    $props = $this->actingAs($admin)->get(route('governance.dashboard'))->assertOk()->viewData('page')['props'];

    // The export panel is offered to someone who may use it, with the opt-in gate reflected.
    expect($props['ledgerExport']['canExport'])->toBeTrue()
        ->and($props['ledgerExport']['url'])->toBe(route('governance.ledger.export'));

    $en = json_decode((string) file_get_contents(base_path('resources/js/lang/en.json')), true);
    expect($en['governance']['omitted'])->toHaveKey('signedPdf');
    expect(strtolower($en['governance']['omitted']['signedPdf']))->toContain('no signing key');

    /*
     * GOV.P3's lesson: pinning the copy is not pinning the page. Emptying the render loop left the
     * translation file intact and a copy-only test green, so the rendered list is asserted too.
     */
    $source = (string) file_get_contents(base_path('resources/js/pages/Governance/Dashboard.vue'));
    $stripped = preg_replace('~<!--.*?-->~s', ' ', $source) ?? $source;

    expect(str_contains($stripped, "'confidence', 'breaches', 'kbGaps', 'escalated', 'signedPdf'"))
        ->toBeTrue('the omission list is no longer rendered in full');
    expect(str_contains($stripped, 'governance.omitted.'))->toBeTrue('the page no longer renders the omission copy');

    // The export panel states what the manifest is for, and that exporting is audited.
    expect(str_contains($stripped, 'governance.export.manifestNote'))->toBeTrue();
    expect(str_contains($stripped, 'governance.export.auditNote'))->toBeTrue();

    // The PHI opt-in is never pre-ticked — a ticked box is not a decision (D-176).
    expect(str_contains($stripped, 'const includeFreeText = ref(false)'))->toBeTrue('the free-text opt-in no longer starts off');

    /*
     * ...and whatever the page uses, it must IMPORT. This assertion exists because the browser
     * caught what the suite could not: `ref()` was used without being imported, the build does not
     * type-check, and the page threw "ref is not defined" at runtime while every source-string
     * assertion still passed. A feature test cannot execute Vue — but it can check that a symbol
     * the template depends on is in scope.
     */
    preg_match('~^import \{([^}]*)\} from .vue.;~m', $stripped, $vueImports);
    expect(str_contains($vueImports[1] ?? '', 'ref'))->toBeTrue('Dashboard.vue uses ref() without importing it');
});
