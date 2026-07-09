<?php

namespace Modules\Nursing\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Models\TimesheetLine;
use Modules\Nursing\Models\Visit;
use Modules\Nursing\Models\VisitEvent;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;
use Modules\Scheduling\Models\Resource;

class TimesheetService
{
    public const DURATION_DEVIATION_SETTING = 'nursing.timesheet.duration_deviation_minutes';

    private const DEFAULT_DURATION_DEVIATION_MINUTES = 15;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Derives draft lines from ACTUAL check-in/check-out visit_events. Planned
     * windows are used only as a comparison target for discrepancy flags, never
     * to compute payable minutes.
     *
     * @return Collection<int, TimesheetLine>
     */
    public function generateFromVisits(
        Resource $resource,
        CarbonInterface|string $from,
        CarbonInterface|string $to,
    ): Collection {
        $this->assertSameTenant($resource, 'resource_id');

        $fromDate = CarbonImmutable::parse($from)->startOfDay();
        $toDate = CarbonImmutable::parse($to)->endOfDay();

        $lines = collect();

        Visit::query()
            ->where('resource_id', $resource->id)
            ->whereBetween('scheduled_start_at', [
                $fromDate->toDateTimeString(),
                $toDate->toDateTimeString(),
            ])
            ->orderBy('scheduled_start_at')
            ->get()
            ->each(function (Visit $visit) use ($resource, $lines): void {
                $line = $this->lineForVisit($resource, $visit);

                if ($line instanceof TimesheetLine) {
                    $lines->push($line);
                }
            });

        return $lines;
    }

    public function approve(TimesheetLine $line, User $approver): TimesheetLine
    {
        $this->assertSameTenant($line, 'timesheet_line_id');
        Gate::forUser($approver)->authorize('timesheet.approve');

        $line->forceFill([
            'status' => TimesheetLine::STATUS_APPROVED,
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ])->save();

        return $line->refresh();
    }

    private function lineForVisit(Resource $resource, Visit $visit): ?TimesheetLine
    {
        $checkIn = $this->event($visit, VisitEvent::TYPE_CHECK_IN);

        if (! $checkIn instanceof VisitEvent) {
            return null;
        }

        $checkOut = $this->event($visit, VisitEvent::TYPE_CHECK_OUT);
        $flags = $this->flags($visit, $checkIn, $checkOut);
        $minutes = $checkOut instanceof VisitEvent
            ? (int) $checkIn->occurred_at->diffInMinutes($checkOut->occurred_at)
            : null;

        $existing = TimesheetLine::query()->where('visit_id', $visit->id)->first();
        if ($existing instanceof TimesheetLine && $existing->status === TimesheetLine::STATUS_APPROVED) {
            return $existing;
        }

        $attributes = [
            'resource_id' => $resource->id,
            'visit_id' => $visit->id,
            'date' => $checkIn->occurred_at->toDateString(),
            'started_at' => $checkIn->occurred_at->toDateTimeString(),
            'ended_at' => $checkOut?->occurred_at?->toDateTimeString(),
            'minutes' => $minutes,
            'travel_minutes' => null,
            'discrepancy_flags' => $flags,
            'status' => TimesheetLine::STATUS_DRAFT,
            'approved_by' => null,
            'approved_at' => null,
        ];

        return TimesheetLine::query()->updateOrCreate(
            ['visit_id' => $visit->id],
            $attributes,
        );
    }

    private function event(Visit $visit, string $type): ?VisitEvent
    {
        return VisitEvent::query()
            ->where('visit_id', $visit->id)
            ->where('type', $type)
            ->first();
    }

    /**
     * @return list<string>
     */
    private function flags(Visit $visit, VisitEvent $checkIn, ?VisitEvent $checkOut): array
    {
        $flags = [];

        if (! $checkOut instanceof VisitEvent) {
            $flags[] = TimesheetLine::FLAG_MISSING_CHECK_OUT;
        }

        if ($checkIn->location_source === VisitEvent::SOURCE_MANUAL || $checkOut?->location_source === VisitEvent::SOURCE_MANUAL) {
            $flags[] = TimesheetLine::FLAG_MANUAL_LOCATION;
        }

        $plannedMinutes = $this->plannedMinutes($visit);
        if ($checkOut instanceof VisitEvent && $plannedMinutes !== null) {
            $actualMinutes = (int) $checkIn->occurred_at->diffInMinutes($checkOut->occurred_at);

            if (abs($actualMinutes - $plannedMinutes) > $this->durationDeviationMinutes()) {
                $flags[] = TimesheetLine::FLAG_DURATION_DEVIATION;
            }
        }

        return array_values(array_unique($flags));
    }

    private function plannedMinutes(Visit $visit): ?int
    {
        if ($visit->planned_visit_id === null) {
            return null;
        }

        $planned = PlannedVisit::query()->whereKey($visit->planned_visit_id)->first();

        return $planned instanceof PlannedVisit ? $planned->duration_minutes : null;
    }

    private function durationDeviationMinutes(): int
    {
        return (int) $this->settings->get(
            self::DURATION_DEVIATION_SETTING,
            self::DEFAULT_DURATION_DEVIATION_MINUTES,
        );
    }

    private function assertSameTenant(object $model, string $attribute): void
    {
        if (($model->tenant_id ?? null) !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, (string) ($model->id ?? ''));
        }
    }
}
