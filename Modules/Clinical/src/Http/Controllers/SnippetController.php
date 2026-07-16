<?php

namespace Modules\Clinical\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Clinical\Models\TextSnippet;
use Modules\Clinical\Services\SnippetService;
use Modules\Platform\Models\User;

/**
 * Clinicians manage their PERSONAL snippets; users with snippet.manage.shared
 * manage the SHARED library. RBAC is enforced server-side (SnippetService).
 */
class SnippetController
{
    public function index(Request $request, SnippetService $snippets): Response
    {
        Gate::authorize('note.write');
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $staff = $snippets->staffFor($actor);
        $canManageShared = Gate::allows('snippet.manage.shared');

        $personal = TextSnippet::query()
            ->where('scope', TextSnippet::SCOPE_PERSONAL)
            ->when(
                $staff !== null,
                fn ($q) => $q->where('owner_staff_id', $staff->id),
                fn ($q) => $q->whereRaw('1 = 0'),
            )
            ->orderBy('trigger')
            ->get();

        $shared = TextSnippet::query()->where('scope', TextSnippet::SCOPE_SHARED)->orderBy('trigger')->get();

        return Inertia::render('Clinical/Snippets', [
            'personal' => $personal->map(fn (TextSnippet $s): array => $this->summary($s))->all(),
            'shared' => $shared->map(fn (TextSnippet $s): array => $this->summary($s))->all(),
            'canManageShared' => $canManageShared,
            'placeholders' => SnippetService::PLACEHOLDERS,
            'actions' => [
                'store_url' => route('clinical.snippets.store'),
                'update_url' => route('clinical.snippets.update'),
                'delete_url' => route('clinical.snippets.delete'),
            ],
        ]);
    }

    public function store(Request $request, SnippetService $snippets): RedirectResponse
    {
        $data = $request->validate($this->rules());
        $snippets->create($this->actor($request), $data);

        return back();
    }

    public function update(Request $request, SnippetService $snippets): RedirectResponse
    {
        $data = $request->validate([
            'id' => ['required', 'string'],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:10000'],
            'specialty' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
        ]);

        $snippet = TextSnippet::query()->findOrFail($data['id']);
        $snippets->update($snippet, $this->actor($request), $data);

        return back();
    }

    public function delete(Request $request, SnippetService $snippets): RedirectResponse
    {
        $data = $request->validate(['id' => ['required', 'string']]);
        $snippet = TextSnippet::query()->findOrFail($data['id']);
        $snippets->delete($snippet, $this->actor($request));

        return back();
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(): array
    {
        return [
            'scope' => ['required', 'string', 'in:personal,shared'],
            'trigger' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'specialty' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(TextSnippet $s): array
    {
        return [
            'id' => $s->id,
            'scope' => $s->scope,
            'trigger' => $s->trigger,
            'title' => $s->title,
            'body' => $s->body,
            'specialty' => $s->specialty,
            'active' => $s->active,
        ];
    }
}
