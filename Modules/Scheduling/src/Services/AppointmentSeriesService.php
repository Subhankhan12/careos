<?php

namespace Modules\Scheduling\Services;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Events\AppointmentSeriesLifecycleChanged;
use Modules\Scheduling\Exceptions\BookingConflictException;
use Modules\Scheduling\Exceptions\BookingUnavailableException;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\AppointmentSeries;
use Modules\Scheduling\Models\Service;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\Constraint\BetweenConstraint;

/**
 * Recurring / series clinic appointments (P0P.G8). The RRULE is expanded
 * timezone-correct + DST-safe (the E.2 approach — recurr with the series
 * timezone, then the local wall-clock time re-anchored per occurrence), and
 * EVERY occurrence is booked through the existing no-double-book BookingService.
 *
 * Conflict policy: book all that are free; NEVER silently skip. Occurrences that
 * cannot be booked are returned as a failure report (with the booking reason) for
 * the user to resolve. The series is created with the successful occurrences plus
 * that list.
 */
class AppointmentSeriesService
{
    private const FREQUENCIES = ['daily' => 'DAILY', 'weekly' => 'WEEKLY', 'monthly' => 'MONTHLY'];

    private const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

    /** Hard cap on occurrences a single series may generate. */
    private const MAX_OCCURRENCES = 104;

    public function __construct(
        private readonly BookingService $bookings,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * Preview the occurrence dates with a per-date free/conflict indicator. Books
     * NOTHING.
     *
     * @param  array<string, mixed>  $data
     * @return array{timezone: string, occurrences: list<array{date: string, starts_at: string, free: bool, reason: string|null}>}
     */
    public function preview(array $data, User $actor): array
    {
        $spec = $this->validate($data, $actor);

        $occurrences = [];
        foreach ($this->expand($spec) as $localDate) {
            $startsAt = $this->localStart($localDate, $spec['start_time'], $spec['timezone']);
            $check = $this->bookings->checkAvailability($spec['service_id'], $spec['branch_id'], $startsAt, $spec['resource_ids']);

            $occurrences[] = [
                'date' => $localDate,
                'starts_at' => $startsAt,
                'free' => $check['free'],
                'reason' => $check['reason'],
            ];
        }

        return ['timezone' => $spec['timezone'], 'occurrences' => $occurrences];
    }

    /**
     * Create the series and book every free occurrence through BookingService.
     *
     * @param  array<string, mixed>  $data
     * @return array{series: AppointmentSeries, booked: list<array{date: string, appointment_id: string}>, failures: list<array{date: string, reason: string}>}
     */
    public function create(array $data, User $actor): array
    {
        $spec = $this->validate($data, $actor);
        $service = Service::query()->findOrFail($spec['service_id']);

        $series = AppointmentSeries::query()->create([
            'patient_id' => $spec['patient_id'],
            'service_id' => $spec['service_id'],
            'branch_id' => $spec['branch_id'],
            'resource_ids' => $spec['resource_ids'],
            'rrule' => $spec['rrule'],
            'timezone' => $spec['timezone'],
            'start_time' => $spec['start_time'],
            'duration_minutes' => $service->default_duration_minutes,
            'starts_on' => $spec['starts_on'],
            'ends_on' => $spec['ends_on'],
            'status' => AppointmentSeries::STATUS_ACTIVE,
            'created_by' => (string) $actor->getKey(),
        ]);

        $result = $this->materialize($series->refresh(), $actor);

        Event::dispatch(new AppointmentSeriesLifecycleChanged(
            $series->refresh(),
            'created',
            $actor,
            ['booked' => count($result['booked']), 'failed' => count($result['failures'])],
        ));

        return ['series' => $series, ...$result];
    }

    /**
     * Book any not-yet-booked occurrences of a series. Idempotent (an occurrence
     * already booked is skipped) and blocked once the series is ended.
     *
     * @return array{booked: list<array{date: string, appointment_id: string}>, failures: list<array{date: string, reason: string}>}
     */
    public function materialize(AppointmentSeries $series, User $actor): array
    {
        $this->assertSameTenant($series);
        $this->authorize($series->branch_id, $actor);

        if ($series->status !== AppointmentSeries::STATUS_ACTIVE) {
            return ['booked' => [], 'failures' => []];
        }

        $spec = [
            'rrule' => $series->rrule,
            'timezone' => $series->timezone,
            'start_time' => $series->start_time,
            'starts_on' => $series->starts_on->toDateString(),
            'ends_on' => $series->ends_on?->toDateString(),
        ];

        $alreadyBooked = Appointment::query()
            ->where('series_id', $series->id)
            ->whereNotNull('occurrence_date')
            ->get(['occurrence_date'])
            ->map(fn (Appointment $a): string => $a->occurrence_date->toDateString())
            ->all();

        $booked = [];
        $failures = [];

        foreach ($this->expand($spec) as $localDate) {
            if (in_array($localDate, $alreadyBooked, true)) {
                continue;
            }

            $startsAt = $this->localStart($localDate, $series->start_time, $series->timezone);

            try {
                $appointment = $this->bookings->book(
                    $series->service_id,
                    $series->patient_id,
                    $series->branch_id,
                    $startsAt,
                    $series->resource_ids,
                    $actor,
                    Appointment::SOURCE_STAFF,
                    null,
                    null,
                    $series->id,
                    $localDate,
                );
                $booked[] = ['date' => $localDate, 'appointment_id' => $appointment->id];
            } catch (BookingConflictException) {
                $failures[] = ['date' => $localDate, 'reason' => 'resource_taken'];
            } catch (BookingUnavailableException) {
                $failures[] = ['date' => $localDate, 'reason' => 'outside_availability'];
            }
        }

        return ['booked' => $booked, 'failures' => $failures];
    }

    /**
     * End a series: stop future generation. Past/booked occurrences are NOT
     * touched. Idempotent.
     */
    public function end(AppointmentSeries $series, User $actor): AppointmentSeries
    {
        $this->assertSameTenant($series);
        $this->authorize($series->branch_id, $actor);

        if ($series->status === AppointmentSeries::STATUS_ENDED) {
            return $series;
        }

        $series->forceFill(['status' => AppointmentSeries::STATUS_ENDED])->save();

        Event::dispatch(new AppointmentSeriesLifecycleChanged($series->refresh(), 'ended', $actor));

        return $series;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{patient_id: string, service_id: string, branch_id: string, resource_ids: list<string>, timezone: string, start_time: string, starts_on: string, ends_on: string|null, rrule: string}
     */
    private function validate(array $data, User $actor): array
    {
        $branchId = (string) ($data['branch_id'] ?? '');
        $this->authorize($branchId, $actor);

        $patientId = (string) ($data['patient_id'] ?? '');
        $serviceId = (string) ($data['service_id'] ?? '');

        if (! Patient::query()->whereKey($patientId)->exists()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', $patientId);
        }
        if (! Branch::query()->whereKey($branchId)->exists()) {
            throw CrossTenantReferenceException::forAttribute('branch_id', $branchId);
        }
        $service = Service::query()->findOrFail($serviceId);

        $resourceIds = array_values(array_filter(array_map('strval', (array) ($data['resource_ids'] ?? []))));
        if ($resourceIds === []) {
            throw new InvalidArgumentException('At least one resource is required.');
        }

        $frequency = strtolower((string) ($data['frequency'] ?? ''));
        if (! array_key_exists($frequency, self::FREQUENCIES)) {
            throw new InvalidArgumentException('Unsupported recurrence frequency.');
        }

        $interval = max(1, (int) ($data['interval'] ?? 1));
        $byday = array_values(array_filter(
            array_map(fn ($d): string => strtoupper((string) $d), (array) ($data['byday'] ?? [])),
            fn (string $d): bool => in_array($d, self::WEEKDAYS, true),
        ));

        $timezone = trim((string) ($data['timezone'] ?? ''));
        if ($timezone === '') {
            $timezone = (string) ($service->getAttribute('timezone') ?? Branch::query()->find($branchId)?->getAttribute('timezone') ?? config('app.timezone') ?? 'UTC');
        }
        $this->assertTimezone($timezone);

        $startTime = $this->normalizeTime((string) ($data['start_time'] ?? '09:00'));
        $startsOn = CarbonImmutable::parse((string) ($data['starts_on'] ?? ''), $timezone)->toDateString();

        $endType = (string) ($data['end_type'] ?? 'count');
        $count = null;
        $endsOn = null;

        if ($endType === 'until') {
            $endsOn = CarbonImmutable::parse((string) ($data['ends_on'] ?? ''), $timezone)->toDateString();
            if ($endsOn < $startsOn) {
                throw new InvalidArgumentException('The end date must be on or after the start date.');
            }
        } else {
            $count = (int) ($data['count'] ?? 0);
            if ($count < 1 || $count > self::MAX_OCCURRENCES) {
                throw new InvalidArgumentException('The occurrence count must be between 1 and '.self::MAX_OCCURRENCES.'.');
            }
        }

        $rrule = $this->buildRrule(self::FREQUENCIES[$frequency], $interval, $byday, $count);

        return [
            'patient_id' => $patientId,
            'service_id' => $serviceId,
            'branch_id' => $branchId,
            'resource_ids' => $resourceIds,
            'timezone' => $timezone,
            'start_time' => $startTime,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'rrule' => $rrule,
        ];
    }

    /**
     * @param  list<string>  $byday
     */
    private function buildRrule(string $freq, int $interval, array $byday, ?int $count): string
    {
        $parts = ["FREQ={$freq}", "INTERVAL={$interval}"];

        if ($byday !== []) {
            $parts[] = 'BYDAY='.implode(',', $byday);
        }

        if ($count !== null) {
            $parts[] = "COUNT={$count}";
        }

        return implode(';', $parts);
    }

    /**
     * Expand the RRULE into local occurrence DATES (Y-m-d), DST-safe.
     *
     * @param  array{rrule: string, timezone: string, start_time: string, starts_on: string, ends_on: string|null}  $spec
     * @return list<string>
     */
    private function expand(array $spec): array
    {
        $timezone = $spec['timezone'] === '' ? 'UTC' : $spec['timezone'];
        $startLocal = CarbonImmutable::parse($spec['starts_on'].' '.$spec['start_time'], $timezone);

        $rule = new Rule($spec['rrule'], $startLocal, null, $timezone);

        $transformer = new ArrayTransformer;

        if ($spec['ends_on'] !== null) {
            $endLocal = CarbonImmutable::parse($spec['ends_on'], $timezone)->endOfDay();
            $occurrences = $transformer->transform($rule, new BetweenConstraint($startLocal, $endLocal, true));
        } else {
            $occurrences = $transformer->transform($rule);
        }

        $dates = [];
        foreach ($occurrences as $occurrence) {
            $localDate = CarbonImmutable::instance($occurrence->getStart())->setTimezone($timezone)->toDateString();

            if ($spec['ends_on'] !== null && $localDate > $spec['ends_on']) {
                continue;
            }

            $dates[] = $localDate;

            if (count($dates) >= self::MAX_OCCURRENCES) {
                break;
            }
        }

        return $dates;
    }

    /**
     * The local wall-clock start for an occurrence, as a naive datetime string.
     * Re-anchoring the start_time in the series timezone per occurrence is what
     * keeps 09:00 local across a DST boundary.
     */
    private function localStart(string $localDate, string $startTime, string $timezone): string
    {
        return CarbonImmutable::parse($localDate.' '.$startTime, $timezone === '' ? 'UTC' : $timezone)
            ->format('Y-m-d H:i:s');
    }

    private function assertTimezone(string $timezone): void
    {
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('Unknown timezone.');
        }
    }

    private function normalizeTime(string $time): string
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', trim($time)) !== 1) {
            throw new InvalidArgumentException('start_time must be HH:MM.');
        }

        return trim($time).':00';
    }

    private function assertSameTenant(AppointmentSeries $series): void
    {
        if ($series->tenant_id !== $this->tenants->id()) {
            throw CrossTenantReferenceException::forAttribute('appointment_series_id', $series->id);
        }
    }

    private function authorize(string $branchId, User $actor): void
    {
        if (! Gate::forUser($actor)->allows('appointment.manage', ['branch_id' => $branchId])) {
            throw new AuthorizationException('This user cannot manage appointments.');
        }
    }
}
