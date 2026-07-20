<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AiCore\Models\KbArticle;
use Modules\AiCore\Retrieval\KbRetriever;
use Modules\Audit\Models\AuditEvent;
use Modules\Platform\Models\Role;
use Modules\Platform\Models\RoleAssignment;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

uses(RefreshDatabase::class);

/*
 * CLINIC.W10 — KB admin CRUD over the front-desk agent's grounding source. These tests
 * prove the CRUD goes through the existing KbArticle model + embedding path, is
 * ai.manage-gated, tenant-scoped, and audited — and, critically, that the agent's
 * grounding is UNCHANGED: a deactivated article stops being grounded on because the
 * existing KbRetriever already filters is_active = true. The front-desk agent evals
 * (P.4) are not touched.
 */

function kbCtx(): TenantContext
{
    return app(TenantContext::class);
}

function kbTenant(string $slug): Tenant
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug).' Care', 'slug' => $slug, 'region' => 'eu', 'status' => 'active']);
    kbCtx()->set($tenant);

    return $tenant;
}

function kbUser(Tenant $tenant, string $role): User
{
    kbCtx()->set($tenant); // Role/RoleAssignment are tenant-scoped
    $user = User::factory()->forTenant($tenant)->twoFactorEnabled()->create();
    RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => Role::query()->where('key', $role)->firstOrFail()->id]);

    return $user;
}

function kbAudited(Tenant $tenant, string $action): bool
{
    return AuditEvent::query()->where('tenant_id', $tenant->id)->where('action', $action)->exists();
}

test('KB admin is ai.manage gated and CRUDs articles through the existing model, tenant-scoped and audited', function () {
    $tenant = kbTenant('alpha');
    $admin = kbUser($tenant, 'org_admin');

    kbCtx()->forget();
    $this->actingAs($admin)
        ->get(route('governance.kb.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Governance/KnowledgeBase')->has('articles'));

    // reception holds comms.manage but NOT ai.manage → denied.
    kbCtx()->forget();
    $this->actingAs(kbUser($tenant, 'reception'))->get(route('governance.kb.index'))->assertForbidden();

    // Create.
    kbCtx()->forget();
    $this->actingAs($admin)
        ->post(route('governance.kb.store'), ['title' => 'Booking appointments', 'body' => 'Call reception or use the online portal.', 'tags' => ['booking'], 'is_active' => true])
        ->assertRedirect(route('governance.kb.index'));
    $article = KbArticle::query()->firstOrFail();
    expect($article->title)->toBe('Booking appointments')->and($article->is_active)->toBeTrue();
    expect(kbAudited($tenant, 'kb.article.created'))->toBeTrue();

    // Edit.
    kbCtx()->forget();
    $this->actingAs($admin)
        ->post(route('governance.kb.update', $article->id), ['title' => 'Booking appointments (updated)', 'body' => 'Call reception.', 'tags' => [], 'is_active' => true])
        ->assertRedirect();
    expect($article->refresh()->title)->toBe('Booking appointments (updated)');
    expect(kbAudited($tenant, 'kb.article.updated'))->toBeTrue();

    // Deactivate (soft delete).
    kbCtx()->forget();
    $this->actingAs($admin)->post(route('governance.kb.toggle', $article->id))->assertRedirect();
    expect($article->refresh()->is_active)->toBeFalse();
    expect(kbAudited($tenant, 'kb.article.deactivated'))->toBeTrue();

    // A separate tenant's admin cannot reach this article → 404 (string id, fail-closed).
    $beta = kbTenant('beta');
    kbCtx()->forget();
    $this->actingAs(kbUser($beta, 'org_admin'))
        ->post(route('governance.kb.update', $article->id), ['title' => 'x', 'body' => 'y'])
        ->assertNotFound();
});

test('a deactivated article stops being grounded on (the existing KbRetriever active filter)', function () {
    $tenant = kbTenant('alpha');
    $admin = kbUser($tenant, 'org_admin');

    kbCtx()->forget();
    $this->actingAs($admin)
        ->post(route('governance.kb.store'), ['title' => 'Preparing for a blood test', 'body' => 'Fast for eight hours before your appointment.', 'tags' => ['bloods'], 'is_active' => true])
        ->assertRedirect();
    $article = KbArticle::query()->firstOrFail();

    // While ACTIVE, the retriever grounds on it.
    kbCtx()->set($tenant);
    $activeIds = collect(app(KbRetriever::class)->retrieve('Preparing for a blood test'))->map(fn ($c) => $c->article->id);
    expect($activeIds)->toContain($article->id);

    // Deactivate through the admin screen.
    kbCtx()->forget();
    $this->actingAs($admin)->post(route('governance.kb.toggle', $article->id))->assertRedirect();

    // Now the retriever no longer returns it — the agent can't ground on it.
    kbCtx()->set($tenant);
    $inactiveIds = collect(app(KbRetriever::class)->retrieve('Preparing for a blood test'))->map(fn ($c) => $c->article->id);
    expect($inactiveIds)->not->toContain($article->id);
});
