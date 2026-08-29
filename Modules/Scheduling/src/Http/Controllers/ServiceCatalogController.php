<?php

namespace Modules\Scheduling\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Services\AvailableSlotFinder;
use Modules\Scheduling\Services\ServiceCatalog;

/**
 * SCHED.P2 — the service catalog: the definition every free slot is measured against.
 *
 * WHY THIS SCREEN MATTERS MORE THAN IT LOOKS. A service is not a label; four of its fields are read
 * by the engine at booking time, so editing one silently changes what the practice can sell and what
 * the guard allows:
 *
 *   `default_duration_minutes`  → the length of every generated slot   ({@see AvailableSlotFinder})
 *   `buffer_before/after`       → widen the no-overlap window          (BookingService::assertNoOverlap)
 *   `requires_resource_types`   → which resources must be assigned     (BookingService::assertResourceTypesMatch)
 *   `active`                    → gates the day-board quick-book list AND public exposure
 *   `bookable_online`           → gates public exposure only
 *
 * The page therefore states, in words, which fields change availability — an admin who shortens a
 * duration is changing the booking engine, and should be told so.
 *
 * WRITES GO THROUGH {@see ServiceCatalog}, which already existed with real validation (name, code,
 * duration > 0, buffers >= 0, non-empty resource types, per-tenant unique code, branch links inside
 * a transaction). This controller validates SHAPE and delegates; it never writes a Service itself.
 *
 * NO DELETE AFFORDANCE IS OFFERED — see {@see self::deactivate()} for why.
 *
 * Gated on `admin.manage`, the same permission that guards Branches: this is tenant configuration,
 * not day-to-day scheduling work.
 */
class ServiceCatalogController
{
    public function index(Request $request, ServiceCatalog $catalog): Response
    {
        Gate::authorize('admin.manage');

        $filters = [
            // Filters over REAL columns only.
            'category' => is_string($request->query('category')) ? $request->query('category') : null,
            'state' => in_array($request->query('state'), ['active', 'archived', 'online'], true)
                ? (string) $request->query('state')
                : null,
        ];

        $services = $catalog->list();

        // How many appointments reference each service — a real count, and the reason a service can
        // be archived but not removed.
        $usage = Appointment::query()
            ->selectRaw('service_id, count(*) as total')
            ->groupBy('service_id')
            ->pluck('total', 'service_id');

        $rows = $services
            ->map(fn (Service $service): array => $this->present($service, (int) ($usage[$service->id] ?? 0)))
            ->filter(function (array $row) use ($filters): bool {
                if ($filters['category'] !== null && $row['category'] !== $filters['category']) {
                    return false;
                }

                return match ($filters['state']) {
                    'active' => $row['active'],
                    'archived' => ! $row['active'],
                    'online' => $row['bookableOnline'],
                    default => true,
                };
            })
            ->values()
            ->all();

        return Inertia::render('Admin/ServiceCatalog', [
            'services' => $rows,
            'filters' => $filters,
            'categories' => $services->pluck('category')->filter()->unique()->sort()->values()->all(),
            // Plain counts over the whole catalog, never over the filtered list (D-166/D-174).
            'counts' => [
                'total' => $services->count(),
                'active' => $services->where('active', true)->count(),
                'online' => $services->where('bookable_online', true)->count(),
            ],
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name'])->all(),
            'resourceTypes' => ['practitioner', 'room', 'chair', 'device'],
            /*
             * The slot stride is a CONSTANT in the finder, not a setting. It is surfaced read-only so
             * an admin can see what their duration is rounded against — and the omission card says
             * plainly that it cannot be changed here (D-176: no control that persists nothing).
             */
            'slotStrideMinutes' => AvailableSlotFinder::SLOT_STRIDE_MINUTES,
            'actions' => [
                'storeUrl' => route('admin.services.store'),
                'updateUrl' => route('admin.services.update', ['service' => '__ID__']),
            ],
        ]);
    }

    public function store(Request $request, ServiceCatalog $catalog): RedirectResponse
    {
        Gate::authorize('admin.manage');

        [$attributes, $branchIds] = $this->validated($request, creating: true);

        // ServiceCatalog owns the domain rules (duration > 0, buffers >= 0, unique code, branch
        // links). Those are the authority — this controller does not restate them.
        $this->domainGuarded(fn () => $catalog->create($attributes, $branchIds ?? []));

        return redirect()->route('admin.services.index');
    }

    public function update(Request $request, string $service, ServiceCatalog $catalog): RedirectResponse
    {
        Gate::authorize('admin.manage');

        // Resolved from a string id, tenant-scoped by the model's own global scope: another
        // tenant's service is a 404 here, never an edit.
        $record = Service::query()->whereKey($service)->firstOrFail();

        [$attributes, $branchIds] = $this->validated($request, creating: false);

        $this->domainGuarded(fn () => $catalog->update($record, $attributes, $branchIds));

        return redirect()->route('admin.services.index');
    }

    /**
     * Turn the catalog's domain refusals into an answer the admin can act on.
     *
     * `ServiceCatalog` throws `InvalidArgumentException` for a duplicate code or an invalid
     * duration/buffer/resource-type, and `CrossTenantReferenceException` for a branch outside the
     * tenant. Uncaught, both become a 500 — a refusal the user cannot read and cannot fix.
     *
     * The rules are NOT restated here: the service stays the single authority, and this only
     * changes how its refusal is delivered.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function domainGuarded(callable $write): mixed
    {
        try {
            return $write();
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['service' => $e->getMessage()]);
        } catch (CrossTenantReferenceException) {
            // A branch id from outside this tenant is not a validation hint to be explained back —
            // it is treated as a thing that does not exist here.
            abort(404);
        }
    }

    /**
     * Archiving, NOT deleting — and the distinction is load-bearing.
     *
     * `appointments.service_id` carries an `ON DELETE RESTRICT` foreign key, so the database itself
     * refuses to remove a service any appointment references. That is the right guarantee (a booked
     * visit must keep knowing what it was for), but it surfaces as a raw driver error, which is no
     * kind of answer for an admin.
     *
     * So this screen offers no delete at all. `active = false` is the soft state that stops NEW use
     * — the day-board's quick-book list and public booking both filter on it — while every existing
     * appointment keeps its service intact. Same shape as BRANCH.P1's soft-suspend.
     */
    public function deactivate(Request $request, string $service, ServiceCatalog $catalog): RedirectResponse
    {
        Gate::authorize('admin.manage');

        $record = Service::query()->whereKey($service)->firstOrFail();
        $catalog->update($record, ['active' => $request->boolean('active')]);

        return redirect()->route('admin.services.index');
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<string>|null}
     */
    private function validated(Request $request, bool $creating): array
    {
        $rules = [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            // The finder multiplies this out into slots, so it must be a positive integer. The
            // upper bound keeps a typo from generating a day-long block.
            'default_duration_minutes' => [$creating ? 'required' : 'sometimes', 'integer', 'min:1', 'max:1440'],
            'buffer_before_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'buffer_after_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'requires_resource_types' => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'requires_resource_types.*' => ['string', 'max:40'],
            'bookable_online' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'branch_ids' => ['sometimes', 'array'],
            'branch_ids.*' => ['string'],
        ];

        $data = $request->validate($rules);

        $branchIds = array_key_exists('branch_ids', $data)
            ? array_values(array_map('strval', (array) $data['branch_ids']))
            : null;
        unset($data['branch_ids']);

        return [$data, $branchIds];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Service $service, int $appointmentCount): array
    {
        return [
            'id' => $service->id,
            'name' => $service->name,
            'code' => $service->code,
            'category' => $service->category,
            // THE fields the engine reads — shown as the engine reads them, never recomputed here.
            'durationMinutes' => $service->default_duration_minutes,
            'bufferBeforeMinutes' => $service->buffer_before_minutes,
            'bufferAfterMinutes' => $service->buffer_after_minutes,
            'requiresResourceTypes' => $service->requires_resource_types,
            'bookableOnline' => (bool) $service->bookable_online,
            'active' => (bool) $service->active,
            'branchIds' => $service->branchLinks->pluck('branch_id')->all(),
            // A real count of rows that exist — the reason archive exists instead of delete.
            'appointmentCount' => $appointmentCount,
        ];
    }
}
