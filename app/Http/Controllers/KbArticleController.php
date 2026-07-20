<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\AiCore\Models\KbArticle;
use Modules\AiCore\Retrieval\KbEmbeddingService;
use Modules\Audit\Services\AuditService;
use Modules\Platform\Models\User;

/**
 * KB admin (CLINIC.W10) — CRUD over the tenant's front-desk knowledge base, the
 * source the Front-Desk agent grounds its answers on. This screen curates CONTENT
 * only: the agent's grounding + electric-fence behaviour is UNCHANGED — it still
 * answers only from ACTIVE KB with a citation and refuses medical/symptom/triage/
 * dosing questions (locked by the P.4 eval harness, untouched here). An article
 * deactivated here immediately stops being grounded on, because `KbRetriever`
 * already filters `is_active = true` — this controller adds no retrieval/agent logic.
 *
 * Lives in the app layer because KB curation writes an AUDIT trail (a KB change
 * changes what the agent can say) and AiCore may not depend on Audit. Writes go
 * through the existing `KbArticle` model + `KbEmbeddingService::syncArticle` (the
 * existing embedding path, kept warm on save). Gated on `ai.manage` — the KB is the
 * governed front-desk agent's grounding source, so managing it is governed-AI
 * management, consistent with the W9 governance surfaces (delivery map: governance/KB).
 * Tenant-scoped (KbArticle is BelongsToTenant; ids resolve by string → cross-tenant 404).
 */
class KbArticleController
{
    public function index(Request $request): Response
    {
        Gate::authorize('ai.manage');
        abort_unless($request->user() instanceof User, 403);

        $articles = KbArticle::query()->orderByDesc('is_active')->orderBy('title')->get();

        return Inertia::render('Governance/KnowledgeBase', [
            'articles' => $articles->map(fn (KbArticle $article): array => $this->present($article))->all(),
            'storeUrl' => route('governance.kb.store'),
        ]);
    }

    public function store(Request $request, KbEmbeddingService $embeddings, AuditService $audit): RedirectResponse
    {
        Gate::authorize('ai.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $data = $this->validated($request);

        $article = KbArticle::query()->create($data);
        $embeddings->syncArticle($article); // keep the embedding the agent retrieves on warm
        $this->audit($audit, $actor, 'kb.article.created', $article);

        return redirect()->route('governance.kb.index')->with('status', 'created');
    }

    public function update(Request $request, string $id, KbEmbeddingService $embeddings, AuditService $audit): RedirectResponse
    {
        Gate::authorize('ai.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $article = KbArticle::query()->whereKey($id)->firstOrFail();
        $article->forceFill($this->validated($request))->save();
        $embeddings->syncArticle($article); // content changed → re-embed through the existing path
        $this->audit($audit, $actor, 'kb.article.updated', $article);

        return redirect()->route('governance.kb.index')->with('status', 'updated');
    }

    /**
     * Soft toggle of the ACTIVE flag — the "delete" for a KB article. A deactivated
     * article is no longer grounded on (KbRetriever filters is_active = true) but its
     * content + embeddings are preserved, so it can be brought back.
     */
    public function toggle(Request $request, string $id, AuditService $audit): RedirectResponse
    {
        Gate::authorize('ai.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $article = KbArticle::query()->whereKey($id)->firstOrFail();
        $article->forceFill(['is_active' => ! $article->is_active])->save();

        $this->audit($audit, $actor, $article->is_active ? 'kb.article.activated' : 'kb.article.deactivated', $article);

        return redirect()->route('governance.kb.index')->with('status', $article->is_active ? 'activated' : 'deactivated');
    }

    /**
     * The validated attributes ready for the KbArticle model: tags normalized to a
     * clean string list and is_active defaulted true.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'is_active' => ['boolean'],
        ]);

        $data['tags'] = array_values(array_map(fn (mixed $tag): string => (string) $tag, (array) ($data['tags'] ?? [])));
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(KbArticle $article): array
    {
        return [
            'id' => $article->id,
            'title' => $article->title,
            'body' => $article->body,
            'tags' => $article->tags ?? [],
            'is_active' => $article->is_active,
            'updateUrl' => route('governance.kb.update', $article->id),
            'toggleUrl' => route('governance.kb.toggle', $article->id),
        ];
    }

    private function audit(AuditService $audit, User $actor, string $action, KbArticle $article): void
    {
        $audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => $action,
            'resource_type' => 'kb_article',
            'resource_id' => $article->id,
            'context' => ['title' => $article->title, 'is_active' => $article->is_active],
        ]);
    }
}
