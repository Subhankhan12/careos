<?php

use App\Services\KbArticleService;
use Database\Seeders\DemoClinicSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\AiCore\Models\KbArticle;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * GOV.P3 — KB admin parity + G5 (last saved by).
 *
 * The trap on this screen is the one GOV.P1 already refused once: the wireframe ranks "knowledge-base
 * gaps" — the questions the agent could not answer — and scores articles by usefulness. **Nothing in
 * CareOS records either** (the governance audit's grep found zero hits), so both would be assembled
 * out of nothing. The re-assertion below scans for them under every name they could come back as.
 *
 * What IS real and therefore shown: the article's own fields, who last saved it (read from the audit
 * trail, not a duplicated column), and a plain COUNT of drafts that cited it as a grounding source.
 */

function gkbSeed(): Tenant
{
    Storage::fake('local');
    (new DemoClinicSeeder)->run();

    $tenant = Tenant::query()->where('slug', DemoClinicSeeder::TENANT_SLUG)->firstOrFail();
    app(TenantContext::class)->set($tenant);

    return $tenant;
}

function gkbUser(Tenant $tenant, string $roleKey = 'org_admin'): User
{
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role_id' => Role::query()->where('key', $roleKey)->firstOrFail()->id,
    ]);

    return $user;
}

/**
 * @return array<string, mixed>
 */
function gkbProps($test, User $actor): array
{
    return $test->actingAs($actor)->get(route('governance.kb.index'))->assertOk()->viewData('page')['props'];
}

test('the KB fixture is representative — both states, and savers to tell apart', function () {
    gkbSeed();

    $articles = KbArticle::query()->get();

    // POSITIVE CONTROL (D-174): there is an ACTIVE set and an ARCHIVED one, so "shows both states"
    // is a claim about real rows rather than about an empty table.
    expect($articles->where('is_active', true)->count())->toBe(4)
        ->and($articles->where('is_active', false)->count())->toBe(1);

    /*
     * Three articles were created directly by the seeder (no audit row) and two through the REAL
     * save path by two DIFFERENT people. That mix is the point: it gives the screen real savers to
     * distinguish AND real rows that must honestly show no author.
     */
    $savers = DB::table('audit_events')
        ->where('resource_type', 'kb_article')
        ->whereIn('action', KbArticleService::SAVE_ACTIONS)
        ->distinct()
        ->pluck('actor_id');

    expect($savers)->toHaveCount(2);
});

test('the list renders the REAL articles, both states, with nothing fabricated', function () {
    $tenant = gkbSeed();
    $props = gkbProps($this, gkbUser($tenant));

    expect($props['articles'])->toHaveCount(5)
        ->and($props['counts']['active'])->toBe(4)
        ->and($props['counts']['archived'])->toBe(1);

    // The counts are plain row counts — they match the database, not an arithmetic of their own.
    expect($props['counts']['active'])->toBe(KbArticle::query()->where('is_active', true)->count());

    $archived = collect($props['articles'])->firstWhere('is_active', false);
    expect($archived)->not->toBeNull();
    expect($archived['title'])->toBe('Sommeröffnungszeiten 2025');

    // Every rendered field is a real column or a real derived fact — no score, no rank, no grade.
    foreach ($props['articles'] as $article) {
        expect(array_keys($article))->toEqualCanonicalizing([
            'id', 'title', 'body', 'tags', 'is_active', 'updatedAt',
            'lastSavedBy', 'lastSavedAt', 'groundedDrafts', 'updateUrl', 'toggleUrl',
        ]);
    }
});

test('G5 — last saved by is the REAL saver, and honestly absent where nobody saved it', function () {
    $tenant = gkbSeed();
    $props = gkbProps($this, gkbUser($tenant));

    $byTitle = collect($props['articles'])->keyBy('title');

    /*
     * The two saved through the real path carry their real savers — and they are DIFFERENT people,
     * which is what makes this an assertion about attribution rather than about a constant.
     */
    $reception = $byTitle['Rezepte und Wiederholungsrezepte']['lastSavedBy'];
    $admin = $byTitle['Sommeröffnungszeiten 2025']['lastSavedBy'];

    expect($reception)->not->toBeNull()
        ->and($admin)->not->toBeNull()
        ->and($reception)->not->toBe($admin);

    // Each name is a REAL user of this tenant — not a label, not a default.
    expect(User::query()->where('name', $reception)->exists())->toBeTrue()
        ->and(User::query()->where('name', $admin)->exists())->toBeTrue();

    // ...and it matches the actor on the article's own latest save row.
    $article = KbArticle::query()->where('title', 'Sommeröffnungszeiten 2025')->firstOrFail();
    $latest = DB::table('audit_events')
        ->where('resource_type', 'kb_article')
        ->where('resource_id', $article->id)
        ->whereIn('action', KbArticleService::SAVE_ACTIONS)
        ->orderByDesc('occurred_at')
        ->first();
    expect(User::query()->whereKey($latest->actor_id)->value('name'))->toBe($admin);

    /*
     * THE HONEST ABSENCE. The three seeded articles were never saved through the app, so the trail
     * records no author — and the screen says so rather than attributing them to somebody. This is
     * the control that makes the two names above meaningful (D-184-style).
     */
    foreach (['Öffnungszeiten und Erreichbarkeit', 'Termine absagen und verschieben', 'Rechnungen und Zahlung'] as $title) {
        expect($byTitle[$title]['lastSavedBy'])->toBeNull("'{$title}' was attributed to somebody who never saved it")
            ->and($byTitle[$title]['lastSavedAt'])->toBeNull();
    }

    // The page renders that null as an explicit statement, not a blank.
    $en = json_decode((string) file_get_contents(base_path('resources/js/lang/en.json')), true);
    expect(strtolower($en['kb']['savedBy']['never']))->toContain('no author on record');
});

test('a save through the screen updates the saver — through the EXISTING service, and audited', function () {
    $tenant = gkbSeed();
    $editor = gkbUser($tenant);

    $article = KbArticle::query()->where('title', 'Öffnungszeiten und Erreichbarkeit')->firstOrFail();

    // POSITIVE CONTROL: it has no saver right now, so the change below is attributable to this edit.
    $before = collect(gkbProps($this, $editor)['articles'])->firstWhere('id', $article->id);
    expect($before['lastSavedBy'])->toBeNull();

    $this->actingAs($editor)->post(route('governance.kb.update', $article->id), [
        'title' => 'Öffnungszeiten und Erreichbarkeit',
        'body' => $article->body."\n\nÜber Weihnachten ist die Praxis vom 24.12. bis 2.1. geschlossen.",
        'tags' => ['opening-hours', 'contact'],
        'is_active' => true,
    ])->assertRedirect(route('governance.kb.index'));

    $after = collect(gkbProps($this, $editor)['articles'])->firstWhere('id', $article->id);
    expect($after['lastSavedBy'])->toBe($editor->name)
        ->and($after['lastSavedAt'])->not->toBeNull();

    // The write went through the real path: the row changed AND an audit row names the actor.
    expect($article->refresh()->body)->toContain('Weihnachten');
    expect(
        DB::table('audit_events')
            ->where('resource_type', 'kb_article')
            ->where('resource_id', $article->id)
            ->where('action', KbArticleService::ACTION_UPDATED)
            ->where('actor_id', (string) $editor->id)
            ->exists()
    )->toBeTrue();
});

test('archiving goes through the real toggle and stops the agent grounding on it', function () {
    $tenant = gkbSeed();
    $editor = gkbUser($tenant);

    $article = KbArticle::query()->where('title', 'Rechnungen und Zahlung')->firstOrFail();
    expect($article->is_active)->toBeTrue();

    $this->actingAs($editor)->post(route('governance.kb.toggle', $article->id))
        ->assertRedirect(route('governance.kb.index'));

    expect($article->refresh()->is_active)->toBeFalse();

    /*
     * The consequence the screen claims: a deactivated article is no longer grounded on. Asserted
     * against the retriever's own rule (`is_active = true`) rather than against the copy, so the
     * claim is tied to the code that enforces it (D-184).
     */
    expect(KbArticle::query()->where('is_active', true)->pluck('id')->all())
        ->not->toContain($article->id);

    // Audited as a deactivation, by this actor.
    expect(
        DB::table('audit_events')
            ->where('resource_id', $article->id)
            ->where('action', KbArticleService::ACTION_DEACTIVATED)
            ->where('actor_id', (string) $editor->id)
            ->exists()
    )->toBeTrue();

    // ...and it is still listed, in the archived group — archiving preserves, it does not delete.
    $props = gkbProps($this, $editor);
    expect($props['counts']['archived'])->toBe(2);
    expect(collect($props['articles'])->firstWhere('id', $article->id))->not->toBeNull();
});

test('grounding usage is a REAL recorded count, and zero where nothing cited it', function () {
    $tenant = gkbSeed();
    $props = gkbProps($this, gkbUser($tenant));

    /*
     * The draft engine records `{type: kb_article, id}` on the lines it grounds, and the demo's
     * edited-then-approved draft cites one article. So exactly one article has a non-zero count —
     * which is both the positive control and the proof that the number is read, not invented.
     */
    $cited = collect($props['articles'])->filter(fn (array $a): bool => $a['groundedDrafts'] > 0);
    expect($cited)->toHaveCount(1);
    expect($cited->first()['groundedDrafts'])->toBe(1);

    // Everything else is honestly zero.
    expect(collect($props['articles'])->where('groundedDrafts', 0))->toHaveCount(4);

    // The list is NOT ordered by usage — the cited article is not forced to the top.
    $titles = collect($props['articles'])->pluck('title')->all();
    $expected = KbArticle::query()->orderByDesc('is_active')->orderBy('title')->pluck('title')->all();
    expect($titles)->toBe($expected, 'the list is ordered by state and title, never by usage');
});

test('KB admin is ai.manage gated and tenant-scoped, fail-closed', function () {
    $tenant = gkbSeed();

    // A user with no role holds no permission.
    $nobody = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    $this->actingAs($nobody)->get(route('governance.kb.index'))->assertForbidden();

    // POSITIVE CONTROL: the permitted user gets in, so the 403 is the gate and not a broken route.
    $admin = gkbUser($tenant);
    $this->actingAs($admin)->get(route('governance.kb.index'))->assertOk();

    /*
     * Cross-tenant: an article id from ANOTHER tenant is a 404, not someone else's article. Pinned
     * at the route with a real row on the other side, so it would resolve without the tenant scope
     * (D-182) — and no outer middleware can answer first, because the actor is a legitimate,
     * permitted user of their own tenant.
     */
    $other = Tenant::query()->create(['name' => 'Beta Clinic', 'slug' => 'beta', 'region' => 'eu', 'status' => 'active']);
    app(TenantContext::class)->set($other);
    $foreign = KbArticle::query()->create([
        'title' => 'Beta opening hours',
        'body' => 'Beta clinic hours.',
        'tags' => [],
        'is_active' => true,
    ]);
    app(TenantContext::class)->set($tenant);

    expect($foreign->refresh()->tenant_id)->toBe($other->id);

    $this->actingAs($admin)->post(route('governance.kb.update', $foreign->id), [
        'title' => 'Hijacked', 'body' => 'Should never happen.', 'tags' => [], 'is_active' => true,
    ])->assertNotFound();

    $this->actingAs($admin)->post(route('governance.kb.toggle', $foreign->id))->assertNotFound();

    app(TenantContext::class)->set($other);
    expect($foreign->refresh()->title)->toBe('Beta opening hours')
        ->and($foreign->is_active)->toBeTrue();
});

test('THE RE-ASSERTION: no gap ranking, no coverage or quality score, under any name', function () {
    $tenant = gkbSeed();
    $props = gkbProps($this, gkbUser($tenant));

    // POSITIVE CONTROL (D-174): a non-empty payload with real articles, so the scan reads content.
    expect($props['articles'])->not->toBeEmpty();

    $squashed = preg_replace('~[^a-z0-9]~', '', strtolower(json_encode($props) ?: '')) ?? '';
    expect(strlen($squashed))->toBeGreaterThan(500);

    /*
     * Every name the refused feature could return under. "gap" and "coverage" are the wireframe's
     * own words; the rest are the shapes it would be rebuilt as.
     */
    foreach ([
        'kbgap', 'gapranking', 'mostmissed', 'missedtopic', 'coveragescore', 'coveragepct',
        'qualityscore', 'confidencescore', 'usefulness', 'suggestedarticle', 'articlerank',
        'ungrounded', 'effectiveness', 'performance',
    ] as $token) {
        expect(str_contains($squashed, $token))->toBeFalse("the KB payload carries '{$token}'");
    }

    // D-173 — the scan follows the files it depends on.
    foreach ([
        base_path('resources/js/pages/Governance/KnowledgeBase.vue'),
        base_path('app/Http/Controllers/KbArticleController.php'),
        base_path('app/Services/KbArticleService.php'),
    ] as $path) {
        expect(file_exists($path))->toBeTrue(basename($path).' is missing — this fence would scan nothing');
    }

    $source = (string) file_get_contents(base_path('resources/js/pages/Governance/KnowledgeBase.vue'));
    $stripped = preg_replace('~<!--.*?-->~s', ' ', $source) ?? $source;
    $stripped = preg_replace('~/\*.*?\*/~s', ' ', $stripped) ?? $stripped;

    /*
     * D-169 — nothing may be tinted by a judgment about an article. Binding style to the recorded
     * ACTIVE state is identity, not judgment, and stays permitted — the same line PT.P3 drew.
     */
    preg_match_all('~:class="([^"]*)"~', $stripped, $bindings);
    foreach ($bindings[1] ?? [] as $binding) {
        foreach (['quality', 'score', 'rank', 'coverage', 'sever', 'stale'] as $judgment) {
            expect(str_contains(strtolower($binding), $judgment))->toBeFalse("a class binding is driven by '{$judgment}'");
        }
    }

    /*
     * The omission is STATED, not silently dropped. Two halves, and BOTH need pinning:
     *
     *   1. the copy exists and says the right thing — copy is what rots quietly;
     *   2. the page actually RENDERS it. A mutation caught this: emptying the render loop
     *      (`v-for="key in []"`) left the copy in place and the suite green, so the statement could
     *      have disappeared from the screen while every assertion still passed. Asserting the keys
     *      alone pins a translation file, not a page.
     */
    $en = json_decode((string) file_get_contents(base_path('resources/js/lang/en.json')), true);
    expect($en['kb']['omitted'])->toHaveKeys(['gaps', 'quality']);
    expect(strtolower($en['kb']['omitted']['gaps']))->toContain('nothing records');
    expect(strtolower($en['kb']['omitted']['quality']))->toContain('does not grade');

    // NB: Pest reads extra toContain() arguments as MORE NEEDLES, not as a message — so the
    // message goes on toBeTrue() instead.
    expect(str_contains($stripped, "'gaps', 'quality'"))->toBeTrue('the omission list is no longer rendered');
    expect(str_contains($stripped, 'kb.omitted.'))->toBeTrue('the page no longer renders the omission copy');
});
