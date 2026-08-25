<?php

namespace App\Http\Controllers;

use App\Services\KbArticleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\AiCore\Models\AgentAction;
use Modules\AiCore\Models\KbArticle;
use Modules\Platform\Models\User;

/**
 * KB admin (CLINIC.W10, brought to parity by GOV.P3) — CRUD over the tenant's front-desk knowledge
 * base, the source the Front-Desk agent grounds its answers on. This screen curates CONTENT only:
 * the agent's grounding + electric-fence behaviour is UNCHANGED — it still answers only from ACTIVE
 * KB with a citation and refuses medical/symptom/triage/dosing questions (locked by the P.4 eval
 * harness, untouched here). An article deactivated here immediately stops being grounded on, because
 * `KbRetriever` already filters `is_active = true`.
 *
 * Lives in the app layer because KB curation writes an AUDIT trail (a KB change changes what the
 * agent can say) and AiCore may not depend on Audit. Every write goes through
 * {@see KbArticleService} — the one path that saves, re-embeds and audits — so the controller and
 * the demo seeder produce identical, indistinguishable records. Gated on `ai.manage`; tenant-scoped
 * (KbArticle is BelongsToTenant; ids resolve by string → cross-tenant/missing = 404).
 *
 * ── WHAT THIS SCREEN DELIBERATELY DOES NOT SHOW ────────────────────────────────────────────────
 *
 * The wireframe ranks "knowledge-base gaps" — the questions patients asked that the agent could not
 * ground — and scores articles by how useful they were. **Nothing in CareOS records either.** There
 * is no ungrounded-question telemetry anywhere (the governance audit's grep found zero hits), so a
 * ranking would be a list assembled from nothing, and a quality score would be a judgment about
 * staff-authored content that no code could source (D-170). Both are omitted, and the screen SAYS SO
 * rather than leaving a reader to assume the panel is broken.
 *
 * What IS real, and therefore shown: how many agent drafts recorded this article as a grounding
 * source. That is a plain count of `{type: kb_article, id}` refs the draft engine really wrote onto
 * its lines — never a ranking, never presented as importance or quality.
 */
class KbArticleController
{
    public function index(Request $request): Response
    {
        Gate::authorize('ai.manage');
        abort_unless($request->user() instanceof User, 403);

        $articles = KbArticle::query()->orderByDesc('is_active')->orderBy('title')->get();

        // G5 + usage resolved in ONE query each, not one per row (the PT.P5 pattern).
        $savers = $this->lastSavedBy($articles->pluck('id')->all());
        $groundings = $this->groundingCounts();

        return Inertia::render('Governance/KnowledgeBase', [
            'articles' => $articles
                ->map(fn (KbArticle $article): array => $this->present($article, $savers, $groundings))
                ->all(),
            'storeUrl' => route('governance.kb.store'),
            // Plain row counts — facts, not derived figures (D-166: a tile states a recorded number).
            'counts' => [
                'active' => $articles->where('is_active', true)->count(),
                'archived' => $articles->where('is_active', false)->count(),
            ],
        ]);
    }

    public function store(Request $request, KbArticleService $service): RedirectResponse
    {
        Gate::authorize('ai.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $service->create($this->validated($request), $actor);

        return redirect()->route('governance.kb.index')->with('status', 'created');
    }

    public function update(Request $request, string $id, KbArticleService $service): RedirectResponse
    {
        Gate::authorize('ai.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $article = KbArticle::query()->whereKey($id)->firstOrFail();
        $service->update($article, $this->validated($request), $actor);

        return redirect()->route('governance.kb.index')->with('status', 'updated');
    }

    /**
     * Soft toggle of the ACTIVE flag — the "delete" for a KB article. A deactivated article is no
     * longer grounded on but its content + embeddings are preserved, so it can be brought back.
     */
    public function toggle(Request $request, string $id, KbArticleService $service): RedirectResponse
    {
        Gate::authorize('ai.manage');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $article = KbArticle::query()->whereKey($id)->firstOrFail();
        $service->toggle($article, $actor);

        return redirect()->route('governance.kb.index')
            ->with('status', $article->is_active ? 'activated' : 'deactivated');
    }

    /**
     * The validated attributes ready for the KbArticle model: tags normalized to a clean string list
     * and is_active defaulted true.
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
     * G5 — who last saved each article, READ FROM THE AUDIT TRAIL rather than from a new column.
     *
     * The trail already records the actor of every KB save ({@see KbArticleService::SAVE_ACTIONS}),
     * so an `updated_by` column would be a second copy of the same fact — one that could drift, and
     * one that would need a backfill for existing rows, which could only ever be a guess. Reading
     * the trail means an article nobody has saved through the real path reports NO saver, honestly,
     * instead of being attributed to somebody who never touched it.
     *
     * @param  list<string>  $articleIds
     * @return array<string, array{name: string, at: string}>
     */
    private function lastSavedBy(array $articleIds): array
    {
        if ($articleIds === []) {
            return [];
        }

        // The most recent save per article, in one pass over this tenant's own audit rows.
        $rows = DB::table('audit_events')
            ->select(['resource_id', 'actor_id', 'occurred_at'])
            ->where('resource_type', 'kb_article')
            ->whereIn('action', KbArticleService::SAVE_ACTIONS)
            ->whereIn('resource_id', $articleIds)
            ->orderByDesc('occurred_at')
            ->get();

        $latest = [];
        foreach ($rows as $row) {
            // Ordered newest-first, so the first row seen for an article is its latest save.
            $latest[$row->resource_id] ??= $row;
        }

        $names = User::query()
            ->whereIn('id', array_values(array_filter(array_map(fn ($row): ?string => $row->actor_id, $latest))))
            ->pluck('name', 'id');

        $savedBy = [];
        foreach ($latest as $articleId => $row) {
            $name = $row->actor_id !== null ? ($names[$row->actor_id] ?? null) : null;

            // A saver we cannot name (a user outside this tenant, or one since removed) is left out
            // entirely — the row then shows the honest em-dash rather than an id.
            if ($name === null) {
                continue;
            }

            $savedBy[$articleId] = ['name' => $name, 'at' => (string) $row->occurred_at];
        }

        return $savedBy;
    }

    /**
     * How many agent drafts recorded each article as a grounding source — a REAL count of the
     * `{type: kb_article, id}` refs the draft engine wrote onto its lines.
     *
     * This is usage, not merit: it is never ranked, never sorted by, and never presented as quality
     * or importance. An article with none simply has none.
     *
     * @return array<string, int>
     */
    private function groundingCounts(): array
    {
        $counts = [];

        foreach (AgentAction::query()->get(['proposed_output']) as $action) {
            $lines = is_array($action->proposed_output['lines'] ?? null) ? $action->proposed_output['lines'] : [];
            $seen = [];

            foreach ($lines as $line) {
                $source = is_array($line) && is_array($line['source'] ?? null) ? $line['source'] : null;

                if (($source['type'] ?? null) !== 'kb_article') {
                    continue;
                }

                $id = (string) ($source['id'] ?? '');

                // One draft citing an article twice is ONE grounding, not two.
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, array{name: string, at: string}>  $savers
     * @param  array<string, int>  $groundings
     * @return array<string, mixed>
     */
    private function present(KbArticle $article, array $savers, array $groundings): array
    {
        return [
            'id' => $article->id,
            'title' => $article->title,
            'body' => $article->body,
            'tags' => $article->tags ?? [],
            'is_active' => $article->is_active,
            'updatedAt' => $article->updated_at?->toIso8601String(),
            // G5 — null where the trail records no save, which the page renders as "—".
            'lastSavedBy' => $savers[$article->id]['name'] ?? null,
            'lastSavedAt' => $savers[$article->id]['at'] ?? null,
            // A recorded count, never a score.
            'groundedDrafts' => $groundings[$article->id] ?? 0,
            'updateUrl' => route('governance.kb.update', $article->id),
            'toggleUrl' => route('governance.kb.toggle', $article->id),
        ];
    }
}
