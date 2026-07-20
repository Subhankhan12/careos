<?php

namespace App\Http\Controllers;

use App\Services\BranchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\BranchHours;
use Modules\Platform\Models\User;

/**
 * Branch management for the tenant admin (admin.manage). App-layer controller so the
 * deactivation guard can span Platform (Branch) + Scheduling (appointments/resources)
 * without breaking module boundaries. All writes go through {@see BranchService} and are
 * audited by the AppServiceProvider model hooks. Deactivation is a soft `active=false`
 * (never a hard delete — appointments/encounters/charges reference a branch), BLOCKED
 * when the branch still has future appointments so scheduled care is never orphaned.
 */
class BranchController
{
    /** Carbon dayOfWeek order, Sunday-first. */
    private const WEEKDAYS = [0, 1, 2, 3, 4, 5, 6];

    private const TIMEZONES = [
        'UTC', 'Europe/Zurich', 'Europe/Berlin', 'Europe/Vienna', 'Europe/Paris',
        'Europe/London', 'Europe/Rome', 'Europe/Madrid', 'America/New_York', 'America/Los_Angeles',
    ];

    public function index(BranchService $branches): Response
    {
        Gate::authorize('admin.manage');

        $rows = Branch::query()->orderBy('name')->get();
        $hoursByBranch = BranchHours::query()->whereIn('branch_id', $rows->pluck('id'))->get()->groupBy('branch_id');

        return Inertia::render('Admin/Branches', [
            'branches' => $rows->map(fn (Branch $branch): array => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
                'address_line1' => $branch->address_line1,
                'address_line2' => $branch->address_line2,
                'city' => $branch->city,
                'postal_code' => $branch->postal_code,
                'country' => $branch->country,
                'timezone' => $branch->timezone,
                'active' => $branch->active,
                'active_resources' => $branches->activeResourceCount($branch->id),
                'future_appointments' => $branches->futureAppointmentCount($branch->id),
                'hours' => $this->hoursFor($hoursByBranch->get($branch->id)),
                'updateUrl' => route('admin.branches.update', $branch->id),
                'hoursUrl' => route('admin.branches.hours', $branch->id),
                'deactivateUrl' => route('admin.branches.deactivate', $branch->id),
                'activateUrl' => route('admin.branches.activate', $branch->id),
            ])->all(),
            'weekdays' => self::WEEKDAYS,
            'timezones' => self::TIMEZONES,
            'storeUrl' => route('admin.branches.store'),
            'settingsUrl' => route('settings.index'),
        ]);
    }

    public function store(Request $request, BranchService $branches): RedirectResponse
    {
        Gate::authorize('admin.manage');
        abort_unless($request->user() instanceof User, 403);

        $data = $this->validateBranch($request);

        if (Branch::query()->where('code', $data['code'])->exists()) {
            return back()->withErrors(['code' => 'taken']);
        }

        $branches->create($data);

        return redirect()->route('admin.branches.index')->with('status', 'created');
    }

    public function update(Request $request, string $branch, BranchService $branches): RedirectResponse
    {
        Gate::authorize('admin.manage');
        abort_unless($request->user() instanceof User, 403);

        $model = Branch::query()->whereKey($branch)->firstOrFail();
        $data = $this->validateBranch($request);

        if (Branch::query()->where('code', $data['code'])->whereKeyNot($model->id)->exists()) {
            return back()->withErrors(['code' => 'taken']);
        }

        $branches->update($model, $data);

        return redirect()->route('admin.branches.index')->with('status', 'updated');
    }

    public function hours(Request $request, string $branch, BranchService $branches): RedirectResponse
    {
        Gate::authorize('admin.manage');
        abort_unless($request->user() instanceof User, 403);

        $model = Branch::query()->whereKey($branch)->firstOrFail();

        $validated = $request->validate([
            'days' => ['required', 'array', 'size:7'],
            'days.*.weekday' => ['required', 'integer', 'between:0,6'],
            'days.*.is_closed' => ['required', 'boolean'],
            'days.*.open_time' => ['nullable', 'date_format:H:i'],
            'days.*.close_time' => ['nullable', 'date_format:H:i'],
        ]);

        $days = [];
        foreach ($validated['days'] as $day) {
            if (! $day['is_closed'] && ($day['open_time'] === null || $day['close_time'] === null || $day['close_time'] <= $day['open_time'])) {
                return back()->withErrors(['days' => 'invalid_window']);
            }
            $days[(int) $day['weekday']] = [
                'is_closed' => (bool) $day['is_closed'],
                'open_time' => $day['open_time'] ?? null,
                'close_time' => $day['close_time'] ?? null,
            ];
        }

        $branches->setHours($model, $days);

        return redirect()->route('admin.branches.index')->with('status', 'hoursSaved');
    }

    public function deactivate(Request $request, string $branch, BranchService $branches): RedirectResponse
    {
        Gate::authorize('admin.manage');
        abort_unless($request->user() instanceof User, 403);

        $model = Branch::query()->whereKey($branch)->firstOrFail();

        // SAFETY: never strand scheduled care. A branch with future active appointments
        // must have them reassigned/cancelled first — deactivation is blocked with the count.
        $future = $branches->futureAppointmentCount($model->id);
        if ($future > 0) {
            return back()->withErrors(['branch' => 'has_appointments'])->with('blockedCount', $future);
        }

        $branches->setActive($model, false);

        return redirect()->route('admin.branches.index')->with('status', 'deactivated');
    }

    public function activate(Request $request, string $branch, BranchService $branches): RedirectResponse
    {
        Gate::authorize('admin.manage');
        abort_unless($request->user() instanceof User, 403);

        $model = Branch::query()->whereKey($branch)->firstOrFail();
        $branches->setActive($model, true);

        return redirect()->route('admin.branches.index')->with('status', 'activated');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateBranch(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'code' => ['required', 'string', 'max:40'],
            'address_line1' => ['nullable', 'string', 'max:160'],
            'address_line2' => ['nullable', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],
            'timezone' => ['required', 'string', 'timezone'],
        ]);
    }

    /**
     * The 7-day opening-hours grid for the editor. Configured days come from the rows;
     * an unconfigured day shows a sensible clinic default (weekdays open 09:00–17:00,
     * weekend closed) — nothing is written until the admin saves.
     *
     * @param  Collection<int, BranchHours>|null  $rows
     * @return list<array{weekday: int, is_closed: bool, open_time: string, close_time: string}>
     */
    private function hoursFor(?Collection $rows): array
    {
        $byWeekday = ($rows ?? collect())->keyBy('weekday');

        return collect(self::WEEKDAYS)->map(function (int $weekday) use ($byWeekday): array {
            $row = $byWeekday->get($weekday);
            $isWeekend = $weekday === 0 || $weekday === 6;

            return [
                'weekday' => $weekday,
                'is_closed' => $row !== null ? (bool) $row->is_closed : $isWeekend,
                'open_time' => $row !== null && $row->open_time !== null ? substr($row->open_time, 0, 5) : '09:00',
                'close_time' => $row !== null && $row->close_time !== null ? substr($row->close_time, 0, 5) : '17:00',
            ];
        })->all();
    }
}
