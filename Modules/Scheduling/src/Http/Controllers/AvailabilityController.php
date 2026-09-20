<?php

namespace Modules\Scheduling\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Modules\Platform\Models\Branch;
use Modules\Scheduling\Models\Resource;
use Modules\Scheduling\Models\ResourceAvailability;
use Modules\Scheduling\Services\AvailabilityService;
use Modules\Scheduling\Services\AvailabilityWriter;
use Modules\Scheduling\Services\AvailableSlotFinder;

/**
 * SCHED.P3 — provider / resource availability: the template every free slot is drawn from.
 *
 * ── ONE SOURCE ──────────────────────────────────────────────────────────────────────────────────
 *
 * The "effective availability" this page shows is not computed here. It is
 * {@see AvailabilityService::windowsFor()} — the very method {@see AvailableSlotFinder}
 * calls (at its line 150) to decide whether a candidate slot is inside a resource's hours, and which
 * `BookingService::assertWithinAvailability()` uses to refuse a booking outside them. So the windows
 * an admin reads here are the windows the engine will apply, including the precedence rules:
 *
 *   • a DATED AVAILABLE row REPLACES the weekly template for that date (it does not add to it);
 *   • DATED UNAVAILABLE rows SUBTRACT from whatever base remains;
 *   • a FULL-DAY block (dated, unavailable, no times) empties the day entirely.
 *
 * Duplicating any of that in the page would create a second opinion about what is bookable, which is
 * exactly the drift SCHED.P2 removed for duration and buffers.
 *
 * ── THE CONSEQUENCE, STATED RATHER THAN INVENTED ────────────────────────────────────────────────
 *
 * `assertWithinAvailability()` runs at BOOKING time only. Withdrawing availability later does not
 * move, cancel or flag an appointment already in the book — it simply leaves it outside the
 * template. There is no guard, and this gate did not add one: refusing edits the rest of the system
 * permits would diverge from what booking actually enforces. Instead the page COUNTS what a
 * withdrawal would sit over and says so plainly before anyone saves.
 *
 * Gated on `appointment.manage` — the permission that already governs the day-board and booking,
 * because this is scheduling capacity rather than tenant configuration.
 */
class AvailabilityController
{
    /**
     * The resource types this page filters over.
     *
     * One list, read three times — the validated filter, the payload the page builds its select from,
     * and the empty payload. These were duplicated literals; a fifth type added to one and not the other
     * would have rendered a filter option that silently matched nothing.
     *
     * @var list<string>
     */
    private const RESOURCE_TYPES = ['practitioner', 'room', 'chair', 'device'];

    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly AvailabilityWriter $writer,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('appointment.manage', ['branch_id' => $request->query('branch_id')]);

        $branch = Branch::query()
            ->where('active', true)
            ->when($request->query('branch_id'), fn ($query, $branchId) => $query->whereKey($branchId))
            ->orderBy('name')
            ->first();

        $type = in_array($request->query('type'), self::RESOURCE_TYPES, true)
            ? (string) $request->query('type')
            : null;

        // The week the effective windows are previewed over — a real date range, not a guess.
        $weekStart = CarbonImmutable::parse((string) $request->query('week', CarbonImmutable::now()->toDateString()))
            ->startOfWeek();
        $weekEnd = $weekStart->addDays(6);

        /*
         * NO ACTIVE BRANCH IS A STATE, NOT AN ERROR (DEPLOY-FIX.2).
         *
         * This was `firstOrFail()`, so a tenant with no active branch got an HTTP 404 on the screen that
         * configures bookable hours. It is the identical defect `DEPLOY-FIX.1a` (`b8d5777`) closed on the
         * day-board — recorded there as `QF13c-M1` and deliberately not widened into at the time — and the
         * same two tenants reach the state:
         *   - a FRESHLY PROVISIONED one — `tenant:create` + `tenant:add-admin` leave zero branches;
         *   - a MATURE one — `BranchController::deactivate` refuses only when FUTURE APPOINTMENTS exist,
         *     so a practice with a quiet calendar can deactivate its only site and land here years later.
         *
         * The guard sits after `$type` and `$weekStart` rather than immediately after the lookup, where
         * the day-board's sits, because the empty payload carries both: the page still labels a real week,
         * and a `?type=` the operator typed survives the empty render instead of being silently dropped.
         * `$resources` moved below it because that query reads `$branch->id` and cannot run without one.
         *
         * `Gate::authorize` already ran above, so this changes no authorisation — a caller who may not
         * manage appointments is refused before the branch is looked at, either way.
         */
        if (! $branch instanceof Branch) {
            return Inertia::render('Scheduling/Availability', $this->emptyAvailability($type, $weekStart, $weekEnd));
        }

        $resources = Resource::query()
            ->where('branch_id', $branch->id)
            ->where('active', true)
            ->when($type, fn ($query, $t) => $query->where('type', $t))
            ->orderBy('name')
            ->get();

        return Inertia::render('Scheduling/Availability', [
            'filters' => ['branch_id' => $branch->id, 'type' => $type, 'week' => $weekStart->toDateString()],
            'branches' => Branch::query()->where('active', true)->orderBy('name')->get(['id', 'name'])->all(),
            'resourceTypes' => self::RESOURCE_TYPES,
            'week' => ['start' => $weekStart->toDateString(), 'end' => $weekEnd->toDateString()],
            'resources' => $resources
                ->map(fn (Resource $resource): array => $this->present($resource, $weekStart, $weekEnd))
                ->all(),
            // Plain counts of rows that exist (D-166/D-174) — never a score.
            'counts' => [
                'resources' => $resources->count(),
                'withoutTemplate' => $resources
                    ->filter(fn (Resource $r): bool => ! ResourceAvailability::query()
                        ->where('resource_id', $r->id)->whereNull('date')->exists())
                    ->count(),
                'exceptions' => ResourceAvailability::query()
                    ->whereIn('resource_id', $resources->pluck('id')->all())
                    ->whereNotNull('date')
                    ->count(),
            ],
            /*
             * BRANCH.P1, stated rather than implied: `accepts_online_bookings` gates PUBLIC booking
             * only. A soft-suspended branch still has availability and staff still book into it —
             * a page about hours must not let a reader think the hours are switched off.
             */
            'branchOnlineBookings' => (bool) $branch->accepts_online_bookings,
            'actions' => $this->actionUrls(),
        ]);
    }

    /**
     * The page's payload when the tenant has no active branch.
     *
     * Every collection is empty because it genuinely is — there is no branch to scope hours to. The two
     * scalars deserve a word each, because a wrong default here would be an assertion rather than a blank:
     *
     *   • `branch_id` is NULL, not a string. It is the flag the page reads to tell "no branch at all"
     *     apart from "a branch with no resources" — two different problems with two different answers,
     *     and a tenant with no branch has no resources either, so an unordered pair would tell them to
     *     add a room when what they need is a site.
     *   • `branchOnlineBookings` is false, and the page must NOT render the online-bookings sentence in
     *     this state. There is no branch, so neither "open" nor "suspended" is true of anything; saying
     *     "suspended" would be an unbacked claim about a site that does not exist (D-176). The empty
     *     branch state replaces that whole block rather than showing it with a manufactured value.
     *
     * @return array<string, mixed>
     */
    private function emptyAvailability(?string $type, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): array
    {
        return [
            'filters' => ['branch_id' => null, 'type' => $type, 'week' => $weekStart->toDateString()],
            'branches' => [],
            'resourceTypes' => self::RESOURCE_TYPES,
            'week' => ['start' => $weekStart->toDateString(), 'end' => $weekEnd->toDateString()],
            'resources' => [],
            'counts' => ['resources' => 0, 'withoutTemplate' => 0, 'exceptions' => 0],
            'branchOnlineBookings' => false,
            'actions' => $this->actionUrls(),
        ];
    }

    /**
     * The page's action endpoints. Extracted so the empty payload and the populated one cannot drift —
     * the same reason `DayBoardController::actionUrls()` exists (DEPLOY-FIX.1a).
     *
     * @return array<string, string>
     */
    private function actionUrls(): array
    {
        return [
            'storeUrl' => route('scheduling.availability.store'),
            'updateUrl' => route('scheduling.availability.update', ['availability' => '__ID__']),
            'deleteUrl' => route('scheduling.availability.destroy', ['availability' => '__ID__']),
            'impactUrl' => route('scheduling.availability.impact'),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('appointment.manage', ['branch_id' => $request->input('branch_id')]);

        $data = $this->validated($request);
        $resource = Resource::query()->whereKey($data['resource_id'])->firstOrFail();

        $this->guarded(fn () => $this->writer->create($resource, $data));

        return back();
    }

    public function update(Request $request, string $availability): RedirectResponse
    {
        // Tenant-scoped by the model's own global scope: another tenant's row is a 404, not an edit.
        $record = ResourceAvailability::query()->whereKey($availability)->firstOrFail();
        Gate::authorize('appointment.manage', ['branch_id' => $request->input('branch_id')]);

        $data = $this->validated($request, creating: false);

        $this->guarded(fn () => $this->writer->update($record, $data));

        return back();
    }

    public function destroy(Request $request, string $availability): RedirectResponse
    {
        $record = ResourceAvailability::query()->whereKey($availability)->firstOrFail();
        Gate::authorize('appointment.manage', ['branch_id' => $request->input('branch_id')]);

        $this->writer->delete($record);

        return back();
    }

    /**
     * How many booked appointments a proposed withdrawal would sit over.
     *
     * Read-only, and deliberately NOT a veto: the server does not block the edit, so this endpoint
     * exists to let the page tell the truth before someone saves rather than to pretend a guard
     * exists (D-176 — better an honest warning than a control implying protection it has not got).
     */
    public function impact(Request $request): JsonResponse
    {
        Gate::authorize('appointment.manage', ['branch_id' => $request->input('branch_id')]);

        $data = $request->validate([
            'resource_id' => ['required', 'string'],
            'date' => ['nullable', 'date'],
            'start_time' => ['nullable', 'string'],
            'end_time' => ['nullable', 'string'],
        ]);

        $resource = Resource::query()->whereKey($data['resource_id'])->firstOrFail();

        return response()->json([
            'appointments' => $this->writer->appointmentsUnder(
                $resource,
                $data['date'] ?? null,
                $data['start_time'] ?? null,
                $data['end_time'] ?? null,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating = true): array
    {
        $rules = [
            'resource_id' => [$creating ? 'required' : 'sometimes', 'string'],
            'weekday' => ['nullable', 'integer', 'min:0', 'max:6'],
            'date' => ['nullable', 'date'],
            'start_time' => ['nullable', 'string', 'max:8'],
            'end_time' => ['nullable', 'string', 'max:8'],
            'is_available' => ['sometimes', 'boolean'],
            // The reason the author WROTE. Stored and displayed verbatim; never parsed or classified.
            'reason' => ['nullable', 'string', 'max:255'],
            'branch_id' => ['sometimes', 'string'],
        ];

        $data = $request->validate($rules);
        unset($data['branch_id']);

        return $data;
    }

    /**
     * The MODEL owns the shape rules (weekday range, start/end together, end after start, the
     * full-day-block shape). Uncaught they would surface as a 500; this only changes how the
     * refusal is delivered, and restates none of them.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function guarded(callable $write): mixed
    {
        try {
            return $write();
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['availability' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Resource $resource, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): array
    {
        $rows = ResourceAvailability::query()
            ->where('resource_id', $resource->id)
            ->orderBy('weekday')
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        // Partitioned with a typed loop rather than filter()->map(): a filtered Eloquent collection
        // loses its row type, and the fix belongs in the shape of the code, not in a suppression.
        $template = [];
        $exceptions = [];

        foreach ($rows as $row) {
            if ($row->date === null) {
                $template[] = [
                    'id' => $row->id,
                    'weekday' => $row->weekday,
                    'startTime' => substr((string) $row->start_time, 0, 5),
                    'endTime' => substr((string) $row->end_time, 0, 5),
                ];

                continue;
            }

            $exceptions[] = [
                'id' => $row->id,
                // NOTE: the model documents `date` as `string|null`, but its `date:Y-m-d` cast
                // yields a Carbon at runtime. Parsing (as AvailabilityService::availabilityDate does)
                // is correct either way; the docblock inaccuracy is flagged, not silently relied on.
                'date' => CarbonImmutable::parse($row->date)->toDateString(),
                'isAvailable' => (bool) $row->is_available,
                'startTime' => $row->start_time === null ? null : substr((string) $row->start_time, 0, 5),
                'endTime' => $row->end_time === null ? null : substr((string) $row->end_time, 0, 5),
                'fullDay' => $row->isFullDayBlock(),
                // Displayed exactly as written. Never interpreted, categorised or tinted.
                'reason' => $row->reason,
            ];
        }

        return [
            'id' => $resource->id,
            'name' => $resource->name,
            'type' => $resource->type,
            'template' => $template,
            'exceptions' => $exceptions,
            /*
             * THE EFFECTIVE WINDOWS — straight from the reader the slot finder uses, so the page
             * cannot disagree with what is bookable. Times are sent as HH:MM strings the template
             * prints; the page performs no availability arithmetic of its own.
             */
            'effective' => array_map(
                fn (array $window): array => [
                    'date' => $window['date'],
                    'startTime' => $window['start_at']->format('H:i'),
                    'endTime' => $window['end_at']->format('H:i'),
                ],
                $this->availability->windowsFor($resource, $weekStart->toDateString(), $weekEnd->toDateString()),
            ),
        ];
    }
}
