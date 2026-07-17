<?php

namespace Modules\Nursing\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Nursing\Models\Competency;
use Modules\Nursing\Models\NurseCompetency;
use Modules\Nursing\Services\CompetencyService;
use Modules\Platform\Models\User;
use Modules\Scheduling\Models\Resource;

/**
 * Tenant-authored competency admin: define competencies + set each one's
 * enforcement (hard/soft), and grant/revoke competencies to nurses with an
 * optional expiry. All actions are gated on `competency.manage`.
 */
class CompetencyController
{
    public function index(): Response
    {
        Gate::authorize('competency.manage');

        $nurses = Resource::query()
            ->where('type', Resource::TYPE_PRACTITIONER)
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $grants = NurseCompetency::query()
            ->where('active', true)
            ->get()
            ->groupBy('resource_id');

        return Inertia::render('Nursing/Competencies', [
            'competencies' => Competency::query()
                ->orderBy('name')
                ->get()
                ->map(fn (Competency $c): array => [
                    'id' => $c->id,
                    'code' => $c->code,
                    'name' => $c->name,
                    'description' => $c->description,
                    'enforcement' => $c->enforcement,
                    'active' => $c->active,
                ])
                ->all(),
            'nurses' => $nurses->map(fn (Resource $nurse): array => [
                'id' => $nurse->id,
                'name' => $nurse->name,
                'competencies' => ($grants->get($nurse->id) ?? collect())
                    ->map(fn (NurseCompetency $g): array => [
                        'grant_id' => $g->id,
                        'competency_id' => $g->competency_id,
                        'expires_at' => $g->expires_at?->toDateString(),
                    ])
                    ->values()
                    ->all(),
            ])->all(),
            'enforcements' => Competency::ENFORCEMENTS,
            'actions' => [
                'storeUrl' => route('nursing.competencies.store'),
                'updateUrl' => route('nursing.competencies.update'),
                'grantUrl' => route('nursing.competencies.grant'),
                'revokeUrl' => route('nursing.competencies.revoke'),
                'seedUrl' => route('nursing.competencies.seed'),
            ],
        ]);
    }

    public function store(Request $request, CompetencyService $competencies): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'enforcement' => ['required', 'string', 'in:hard,soft'],
        ]);

        $competencies->create($data, $this->actor($request));

        return back();
    }

    public function update(Request $request, CompetencyService $competencies): RedirectResponse
    {
        $data = $request->validate([
            'competency_id' => ['required', 'string'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'enforcement' => ['sometimes', 'string', 'in:hard,soft'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $competency = Competency::query()->findOrFail($data['competency_id']);
        $competencies->update($competency, $data, $this->actor($request));

        return back();
    }

    public function grant(Request $request, CompetencyService $competencies): RedirectResponse
    {
        $data = $request->validate([
            'resource_id' => ['required', 'string'],
            'competency_id' => ['required', 'string'],
            'granted_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $competencies->grant(
            Resource::query()->findOrFail($data['resource_id']),
            Competency::query()->findOrFail($data['competency_id']),
            ['granted_at' => $data['granted_at'] ?? null, 'expires_at' => $data['expires_at'] ?? null],
            $this->actor($request),
        );

        return back();
    }

    public function revoke(Request $request, CompetencyService $competencies): RedirectResponse
    {
        $data = $request->validate(['grant_id' => ['required', 'string']]);

        $competencies->revoke(
            NurseCompetency::query()->findOrFail($data['grant_id']),
            $this->actor($request),
        );

        return back();
    }

    public function seed(Request $request, CompetencyService $competencies): RedirectResponse
    {
        Gate::authorize('competency.manage');
        $competencies->seedStarter();

        return back();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
