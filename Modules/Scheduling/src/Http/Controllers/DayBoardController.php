<?php

namespace Modules\Scheduling\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\Branch;
use Modules\Platform\Services\BranchHoursService;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\AppointmentResource;
use Modules\Scheduling\Models\AppointmentSeries;
use Modules\Scheduling\Models\Resource;
use Modules\Scheduling\Models\Service;
use Modules\Scheduling\Models\WaitlistOffer;
use Modules\Scheduling\Services\AppointmentService;
use Modules\Scheduling\Services\AvailableSlotFinder;

class DayBoardController
{
    public function __construct(private readonly BranchHoursService $branchHours) {}

    public function __invoke(Request $request, AvailableSlotFinder $slots): Response
    {
        Gate::authorize('appointment.manage', ['branch_id' => $request->query('branch_id')]);

        $date = Carbon::parse($request->query('date', Carbon::today()->toDateString()))->toDateString();
        $branch = Branch::query()
            ->where('active', true)
            ->when($request->query('branch_id'), fn ($query, $branchId) => $query->whereKey($branchId))
            ->orderBy('name')
            ->firstOrFail();

        $services = Service::query()
            ->where('active', true)
            ->orderBy('name')
            ->get();

        return Inertia::render('Scheduling/DayBoard', [
            'filters' => ['date' => $date, 'branch_id' => $branch->id],
            'branches' => Branch::query()->where('active', true)->orderBy('name')->get(['id', 'name'])->all(),
            'resources' => Resource::query()
                ->where('branch_id', $branch->id)
                ->where('active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Resource $resource): array => [
                    'id' => $resource->id,
                    'name' => $resource->name,
                    'type' => $resource->type,
                ])
                ->all(),
            'appointments' => Appointment::query()
                ->with(['resourceLinks'])
                ->where('branch_id', $branch->id)
                ->whereDate('starts_at', $date)
                ->orderBy('starts_at')
                ->get()
                ->map(fn (Appointment $appointment): array => $this->appointmentSummary($appointment))
                ->all(),
            'services' => $services->map(fn (Service $service): array => [
                'id' => $service->id,
                'name' => $service->name,
                'duration' => $service->default_duration_minutes,
            ])->all(),
            'patients' => Patient::query()
                ->orderBy('last_name')
                ->limit(20)
                ->get()
                ->map(fn (Patient $patient): array => [
                    'id' => $patient->id,
                    'name' => trim($patient->first_name.' '.$patient->last_name),
                    'mrn' => $patient->mrn,
                ])
                ->all(),
            'slotPreview' => $services->first() !== null
                ? $slots->forServiceBranchDate($services->first(), $branch->id, $date, 12)
                : [],
            'waitlistOffers' => WaitlistOffer::query()
                ->where('branch_id', $branch->id)
                ->orderByDesc('offered_at')
                ->limit(25)
                ->get()
                ->map(fn (WaitlistOffer $offer): array => $this->offerSummary($offer))
                ->all(),
            // Active recurring series for this branch — so a coordinator can END a series
            // (through the existing scheduling.series.end route) instead of it being URL-only.
            'activeSeries' => AppointmentSeries::query()
                ->where('branch_id', $branch->id)
                ->where('status', AppointmentSeries::STATUS_ACTIVE)
                ->orderByDesc('starts_on')
                ->limit(50)
                ->get()
                ->map(fn (AppointmentSeries $series): array => $this->seriesSummary($series))
                ->all(),
            // SCHED.P1 — plain counts of rows that exist, over the whole day (D-166/D-174).
            'counts' => $this->dayCounts($branch->id, $date),
            // A plain ratio of recorded minutes per lane. No tint, no ranking, no "best lane".
            'utilisation' => $this->utilisation($branch->id, $date),
            'actions' => [
                'transitionUrl' => route('scheduling.day-board.transition'),
                'quickBookUrl' => route('scheduling.day-board.quick-book'),
                'slotsUrl' => route('scheduling.day-board.slots'),
                'openEncounterUrl' => route('scheduling.day-board.open-encounter'),
                'waitlistCandidatesUrl' => route('scheduling.waitlist.candidates'),
                'waitlistOfferUrl' => route('scheduling.waitlist.offer'),
                'waitlistAcceptUrl' => route('scheduling.waitlist.accept'),
                'waitlistDeclineUrl' => route('scheduling.waitlist.decline'),
                'seriesPreviewUrl' => route('scheduling.series.preview'),
                'seriesStoreUrl' => route('scheduling.series.store'),
                'seriesEndUrl' => route('scheduling.series.end'),
            ],
        ]);
    }

    /**
     * Plain counts for the day — each one a number of rows that exist.
     *
     * `waiting` is deliberately "arrived and not yet started", which is a REAL pair of statuses, not
     * a judgment about who has waited too long. There is no threshold anywhere in this method.
     *
     * @return array<string, int>
     */
    private function dayCounts(string $branchId, string $date): array
    {
        $byStatus = Appointment::query()
            ->where('branch_id', $branchId)
            ->whereDate('starts_at', $date)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $count = fn (string $status): int => (int) ($byStatus[$status] ?? 0);

        return [
            'total' => (int) $byStatus->sum(),
            'waiting' => $count(Appointment::STATUS_ARRIVED),
            'inProgress' => $count(Appointment::STATUS_IN_PROGRESS),
            'completed' => $count(Appointment::STATUS_COMPLETED),
            // Booked online today — a recorded source, not an inference.
            'online' => Appointment::query()
                ->where('branch_id', $branchId)
                ->whereDate('starts_at', $date)
                ->where('source', Appointment::SOURCE_ONLINE)
                ->count(),
        ];
    }

    /**
     * Booked minutes per resource for the day, as a PLAIN RATIO of two recorded quantities.
     *
     * Booked = the summed duration of this lane's non-cancelled appointments. Available = the
     * branch's own opening window for that weekday — the same window the slot finder scans, so the
     * denominator is not invented either.
     *
     * WHAT THIS IS NOT (D-169): it is not a score, not a ranking, and nothing downstream may colour
     * by it. There is no "thin"/"busy" band, no best-lane ordering and no forecast — a percentage of
     * two numbers the record already contains is a fact; anything that interprets it is not.
     *
     * @return array<string, array{bookedMinutes: int, availableMinutes: int, percent: int|null}>
     */
    private function utilisation(string $branchId, string $date): array
    {
        $day = Carbon::parse($date);
        $window = $this->branchHours->scanWindow($branchId, $day->dayOfWeek, 7 * 60, 19 * 60);
        $availableMinutes = $window === null ? 0 : max(0, $window['close'] - $window['open']);

        $appointments = Appointment::query()
            ->with('resourceLinks')
            ->where('branch_id', $branchId)
            ->whereDate('starts_at', $date)
            /*
             * REDUNDANT TODAY, AND DELIBERATELY KEPT (D-187). `AppointmentService::transition()`
             * deletes an appointment's resource links when it moves to cancelled or rescheduled, and
             * this sum is per LINK — so such an appointment already contributes nothing and a
             * mutation removing this line changes no result. It stays because it states the intent
             * ("cancelled work is not booked time") independently of that implementation detail: if
             * links were ever retained for cancellations, the ratio would still be right.
             */
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_RESCHEDULED])
            ->get();

        // Minutes per appointment first, then attributed to each resource it holds. The links are
        // queried directly rather than walked through the relation so the row type stays concrete.
        $minutesByAppointment = $appointments
            ->mapWithKeys(fn (Appointment $appointment): array => [
                $appointment->id => (int) $appointment->starts_at->diffInMinutes($appointment->ends_at),
            ]);

        $booked = [];

        foreach (
            AppointmentResource::query()
                ->whereIn('appointment_id', $appointments->pluck('id')->all())
                ->get() as $link
        ) {
            $minutes = (int) ($minutesByAppointment[$link->appointment_id] ?? 0);
            $booked[$link->resource_id] = ($booked[$link->resource_id] ?? 0) + $minutes;
        }

        $out = [];

        foreach (Resource::query()->where('branch_id', $branchId)->where('active', true)->get() as $resource) {
            $bookedMinutes = (int) ($booked[$resource->id] ?? 0);

            $out[$resource->id] = [
                'bookedMinutes' => $bookedMinutes,
                'availableMinutes' => $availableMinutes,
                // An honest null when there is no window to divide by, never a fabricated 0%.
                'percent' => $availableMinutes > 0 ? (int) round($bookedMinutes / $availableMinutes * 100) : null,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function appointmentSummary(Appointment $appointment): array
    {
        $patient = $appointment->patient_id !== null
            ? Patient::query()->find($appointment->patient_id)
            : null;
        $service = Service::query()->find($appointment->service_id);

        // The RAW stored value, parsed as UTC — see the note on `checked_in_at` below.
        $raw = $appointment->getRawOriginal('checked_in_at');
        $checkedInAt = $raw === null || $raw === '' ? null : CarbonImmutable::parse((string) $raw, 'UTC');

        return [
            'id' => $appointment->id,
            'patient_id' => $appointment->patient_id,
            'patient' => $patient !== null ? trim($patient->first_name.' '.$patient->last_name) : null,
            'service_id' => $appointment->service_id,
            'service' => $service?->name,
            'starts_at' => $appointment->starts_at->toDateTimeString(),
            'ends_at' => $appointment->ends_at->toDateTimeString(),
            'status' => $appointment->status,
            /*
             * SCHED.P1 — the actions the SERVER will accept for this appointment's TRUE status,
             * from the same accessor Appointment Detail uses. The board renders exactly these, so an
             * offered action can never end in a refusal. The D-156 confirm→arrive compose is
             * included because both its edges are legal, not because it is special-cased.
             */
            'actions' => AppointmentService::boardActionsFor($appointment->status),
            /*
             * A recorded fact: when reception checked this patient in. Null until they arrive.
             *
             * BOTH VALUES ARE DERIVED FROM THE STORED UTC INSTANT, and the elapsed minutes are
             * computed HERE rather than in the browser. Two separate zone problems make that the
             * only honest option, and browser verification found both:
             *
             *  1. The viewer's machine may be in any zone. A naive "Y-m-d H:i:s" is parsed as
             *     BROWSER-local, so on a UTC−7 machine a check-in half an hour ago read as hours in
             *     the FUTURE and the elapsed clamped to zero.
             *  2. `ApplyTenantLocaleTimezone` sets PHP's default zone to the practice's (here
             *     Europe/Zurich) while `config('app.timezone')` stays UTC — so Eloquent RE-LABELS the
             *     UTC-stored string as Zurich, shifting it two hours. Its own docblock notes that
             *     per-widget datetime→timezone display conversion is still a follow-up.
             *
             * Reading the raw column and parsing it as UTC — the documented storage contract — makes
             * this widget correct regardless of either. (D-091's family: the bug is always someone
             * interpreting a naive timestamp in a zone that was not the one it was written in.)
             */
            'checked_in_at' => $checkedInAt?->toIso8601String(),
            'waiting_minutes' => $checkedInAt === null ? null : max(0, (int) $checkedInAt->diffInMinutes(CarbonImmutable::now('UTC'))),
            'resource_ids' => $appointment->resourceLinks->pluck('resource_id')->all(),
            // APPT.P1 — the drill-in to the Appointment Detail page. A link only; the tile's own
            // actions are unchanged.
            'detail_url' => route('scheduling.appointments.show', $appointment->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function offerSummary(WaitlistOffer $offer): array
    {
        $patient = Patient::query()->find($offer->patient_id);

        return [
            'id' => $offer->id,
            'patient' => $patient !== null ? trim($patient->first_name.' '.$patient->last_name) : null,
            'service_id' => $offer->service_id,
            'starts_at' => $offer->slot_starts_at->toDateTimeString(),
            'ends_at' => $offer->slot_ends_at->toDateTimeString(),
            'status' => $offer->status,
            'expires_at' => $offer->expires_at->toDateTimeString(),
            'booked_appointment_id' => $offer->booked_appointment_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function seriesSummary(AppointmentSeries $series): array
    {
        $patient = Patient::query()->find($series->patient_id);
        $service = Service::query()->find($series->service_id);

        return [
            'id' => $series->id,
            'patient' => $patient !== null ? trim($patient->first_name.' '.$patient->last_name) : null,
            'service' => $service?->name,
            'start_time' => $series->start_time,
            'starts_on' => $series->starts_on->toDateString(),
            'rrule' => $series->rrule,
        ];
    }
}
