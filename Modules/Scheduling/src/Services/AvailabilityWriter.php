<?php

namespace Modules\Scheduling\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\Resource;
use Modules\Scheduling\Models\ResourceAvailability;

/**
 * SCHED.P3 — the write half of availability.
 *
 * `AvailabilityService` reads (`windowsFor`); nothing wrote. Until this gate, `resource_availability`
 * rows were created **only by seeders** — the same shape as the waitlist-create blocker the batch
 * audit found. So this class is new, and it is deliberately thin: the MODEL already validates
 * (weekday 0–6, start/end required together, end after start, the resource must be in this tenant,
 * and a dated row may be a full-day block only when it is an unavailability), and those rules are
 * not restated here.
 *
 * ── WHAT AN EDIT ACTUALLY MEANS ─────────────────────────────────────────────────────────────────
 *
 * Availability decides what patients can book, and `AvailableSlotFinder` reads these very rows, so a
 * change here changes the practice's bookable day from the next search onwards.
 *
 * **IT DOES NOT TOUCH APPOINTMENTS ALREADY IN THE BOOK.** `BookingService::assertWithinAvailability()`
 * runs at BOOKING time only; nothing re-checks when availability later changes. Withdrawing a window
 * that already has a booked appointment under it leaves that appointment exactly where it is —
 * outside the template, still booked, with nobody told.
 *
 * That is the system's real behaviour, and this class does not invent a guard for it. What it does
 * instead is COUNT what a withdrawal would sit over ({@see self::appointmentsUnder()}), so the
 * surface can state the consequence honestly before someone saves. Inventing a block here would be
 * worse than the gap: it would refuse edits the rest of the system permits, and quietly diverge from
 * what booking actually enforces.
 */
class AvailabilityWriter
{
    /**
     * A recurring weekly window, or a dated exception. Shape is validated by the model.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(Resource $resource, array $attributes): ResourceAvailability
    {
        $this->assertResourceIsWritable($resource);

        return DB::transaction(fn (): ResourceAvailability => ResourceAvailability::query()->create([
            ...$this->normalise($attributes),
            'resource_id' => $resource->id,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(ResourceAvailability $availability, array $attributes): ResourceAvailability
    {
        return DB::transaction(function () use ($availability, $attributes): ResourceAvailability {
            $availability->update($this->normalise($attributes));

            return $availability->refresh();
        });
    }

    public function delete(ResourceAvailability $availability): void
    {
        DB::transaction(fn () => $availability->delete());
    }

    /**
     * How many appointments already sit inside a window that is about to stop being available.
     *
     * This is a COUNT OF REAL ROWS, not a judgment and not a guard: the surface uses it to say what
     * a withdrawal would leave stranded. `null` dates mean a recurring window, which cannot be
     * resolved to specific days without a horizon, so only dated rows are answerable — the caller
     * gets `null` and the page says it cannot tell rather than implying zero.
     *
     * Only blocking statuses count; a cancelled appointment is not stranded by anything.
     */
    public function appointmentsUnder(Resource $resource, ?string $date, ?string $startTime, ?string $endTime): ?int
    {
        if ($date === null) {
            return null;
        }

        $day = CarbonImmutable::parse($date)->startOfDay();

        // A full-day block strands everything that day; a timed one only what it overlaps.
        $from = $startTime === null ? $day : $day->addMinutes($this->minutes($startTime));
        $to = $endTime === null ? $day->addDay() : $day->addMinutes($this->minutes($endTime));

        return Appointment::query()
            ->whereIn('status', Appointment::blockingStatuses())
            ->whereHas('resourceLinks', fn ($query) => $query->where('resource_id', $resource->id))
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->count();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalise(array $attributes): array
    {
        $out = [];

        foreach (['weekday', 'start_time', 'end_time', 'date', 'is_available', 'reason'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $out[$key] = $attributes[$key];
            }
        }

        // A dated row and a recurring row are different shapes; carrying both would be ambiguous
        // about which one the finder should use, and the model's own guard would reject it anyway.
        if (($out['date'] ?? null) !== null) {
            $out['weekday'] = null;
        }

        return $out;
    }

    private function assertResourceIsWritable(Resource $resource): void
    {
        // The tenant scope already excludes another tenant's resource, so reaching here with one
        // means it was loaded outside the scope — refuse rather than write across the boundary.
        if (! Resource::query()->whereKey($resource->id)->exists()) {
            throw new InvalidArgumentException('This resource is not available in the current tenant.');
        }
    }

    private function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', substr($time, 0, 5)));

        return ($hours * 60) + $minutes;
    }
}
