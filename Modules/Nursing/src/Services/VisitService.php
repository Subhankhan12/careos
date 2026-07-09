<?php

namespace Modules\Nursing\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Nursing\Events\VisitEventRecorded;
use Modules\Nursing\Models\PlannedVisit;
use Modules\Nursing\Models\ServiceAgreement;
use Modules\Nursing\Models\Visit;
use Modules\Nursing\Models\VisitEvent;
use Modules\Nursing\Models\VisitPlan;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Setting;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

class VisitService
{
    public const PRIVACY_NOTICE_SETTING_KEY = 'nursing.visit.gps_privacy_notice';

    public const PRIVACY_NOTICE_TEXT = 'Location is captured only at check-in and check-out; no continuous tracking, no background location, and no route capture.';

    private const GEOFENCE_REVIEW_METERS_DEFAULT = 250;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Privacy posture (D-E3): GPS is captured at exactly two moments: check-in
     * and check-out. CareOS does not run continuous location tracking,
     * background location collection, or route capture here. Manual proof with
     * a reason is required when GPS is unavailable or denied.
     */
    public function createFromPlannedVisit(PlannedVisit $plannedVisit, string $clientVisitUuid): Visit
    {
        $this->assertSameTenant($plannedVisit, 'planned_visit_id');

        if ($plannedVisit->assigned_resource_id === null) {
            throw new InvalidArgumentException('A planned visit must be assigned before execution.');
        }

        $visitPlan = VisitPlan::query()->whereKey($plannedVisit->visit_plan_id)->firstOrFail();
        $agreement = ServiceAgreement::query()->whereKey($visitPlan->service_agreement_id)->firstOrFail();

        return Visit::query()->create([
            'planned_visit_id' => $plannedVisit->id,
            'patient_id' => $plannedVisit->patient_id,
            'resource_id' => $plannedVisit->assigned_resource_id,
            'branch_id' => $agreement->branch_id,
            'scheduled_start_at' => $plannedVisit->window_start_at,
            'status' => Visit::STATUS_SCHEDULED,
            'client_visit_uuid' => $clientVisitUuid,
        ]);
    }

    /**
     * @param  array{latitude: float|int|string, longitude: float|int|string, accuracy_meters?: float|int|string|null}|string|null  $locationOrManualReason
     */
    public function checkIn(
        Visit $visit,
        User $actor,
        array|string|null $locationOrManualReason,
        CarbonInterface|string $deviceTime,
    ): VisitEvent {
        $this->assertSameTenant($visit, 'visit_id');

        if ($visit->status !== Visit::STATUS_SCHEDULED) {
            throw new InvalidArgumentException('Only scheduled visits can be checked in.');
        }

        $event = $this->recordEvent($visit, VisitEvent::TYPE_CHECK_IN, $actor, $locationOrManualReason, $deviceTime);
        $visit->forceFill([
            'checked_in_at' => $event->occurred_at,
            'status' => Visit::STATUS_IN_PROGRESS,
        ])->save();

        return $event;
    }

    /**
     * @param  array{latitude: float|int|string, longitude: float|int|string, accuracy_meters?: float|int|string|null}|string|null  $locationOrManualReason
     */
    public function checkOut(
        Visit $visit,
        User $actor,
        array|string|null $locationOrManualReason,
        CarbonInterface|string $deviceTime,
    ): VisitEvent {
        $this->assertSameTenant($visit, 'visit_id');
        $visit = $visit->refresh();

        if ($visit->checked_in_at === null || $visit->status !== Visit::STATUS_IN_PROGRESS) {
            throw new InvalidArgumentException('A visit must be checked in before check-out.');
        }

        $event = $this->recordEvent($visit, VisitEvent::TYPE_CHECK_OUT, $actor, $locationOrManualReason, $deviceTime);
        $visit->forceFill([
            'checked_out_at' => $event->occurred_at,
            'status' => Visit::STATUS_COMPLETED,
        ])->save();

        return $event;
    }

    /**
     * @param  array{latitude: float|int|string, longitude: float|int|string, accuracy_meters?: float|int|string|null}|string|null  $locationOrManualReason
     */
    private function recordEvent(
        Visit $visit,
        string $type,
        User $actor,
        array|string|null $locationOrManualReason,
        CarbonInterface|string $deviceTime,
    ): VisitEvent {
        $this->ensurePrivacyNoticeStored();
        $location = $this->locationPayload($locationOrManualReason);
        $manualReason = is_string($locationOrManualReason) ? trim($locationOrManualReason) : null;

        if ($location === null && ($manualReason === null || $manualReason === '')) {
            throw new InvalidArgumentException('Manual location fallback requires a reason.');
        }

        if (VisitEvent::query()->where('visit_id', $visit->id)->where('type', $type)->exists()) {
            throw new InvalidArgumentException("Visit {$type} has already been recorded.");
        }

        if ($actor->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('recorded_by', (string) $actor->id);
        }

        $id = (string) Str::ulid();
        $occurredAt = CarbonImmutable::parse($deviceTime);
        $receivedAt = now();
        $source = $location !== null ? VisitEvent::SOURCE_GPS : VisitEvent::SOURCE_MANUAL;
        $distance = $location !== null ? $this->distanceFromPlannedAddress($visit, $location) : null;

        DB::transaction(function () use ($id, $visit, $type, $occurredAt, $receivedAt, $location, $source, $manualReason, $distance, $actor): void {
            if ($location !== null) {
                DB::insert(
                    <<<'SQL'
INSERT INTO visit_events (
    id, tenant_id, visit_id, type, occurred_at, received_at, location, location_index, accuracy_meters,
    location_source, manual_reason, distance_meters, recorded_by, created_at, updated_at
) VALUES (?, ?, ?, ?, ?, ?, ST_GeomFromText(?, 4326), ST_GeomFromText(?, 4326), ?, ?, ?, ?, ?, ?, ?)
SQL,
                    [
                        $id,
                        $visit->tenant_id,
                        $visit->id,
                        $type,
                        $occurredAt->format('Y-m-d H:i:s.u'),
                        $receivedAt->format('Y-m-d H:i:s.u'),
                        sprintf('POINT(%F %F)', $location['longitude'], $location['latitude']),
                        sprintf('POINT(%F %F)', $location['longitude'], $location['latitude']),
                        $location['accuracy_meters'],
                        $source,
                        null,
                        $distance,
                        $actor->id,
                        $receivedAt->toDateTimeString(),
                        $receivedAt->toDateTimeString(),
                    ],
                );

                return;
            }

            DB::insert(
                <<<'SQL'
INSERT INTO visit_events (
    id, tenant_id, visit_id, type, occurred_at, received_at, location, location_index, accuracy_meters,
    location_source, manual_reason, distance_meters, recorded_by, created_at, updated_at
) VALUES (?, ?, ?, ?, ?, ?, NULL, ST_GeomFromText('POINT(0 0)', 4326), NULL, ?, ?, NULL, ?, ?, ?)
SQL,
                [
                    $id,
                    $visit->tenant_id,
                    $visit->id,
                    $type,
                    $occurredAt->format('Y-m-d H:i:s.u'),
                    $receivedAt->format('Y-m-d H:i:s.u'),
                    $source,
                    $manualReason,
                    $actor->id,
                    $receivedAt->toDateTimeString(),
                    $receivedAt->toDateTimeString(),
                ],
            );
        });

        $event = VisitEvent::query()->whereKey($id)->firstOrFail();
        $geofenceFlagged = $distance !== null && $distance > $this->geofenceReviewMeters();

        Event::dispatch(new VisitEventRecorded($visit->refresh(), $event, $actor, [
            'privacy_notice' => self::PRIVACY_NOTICE_TEXT,
            'geofence_flagged' => $geofenceFlagged,
            'geofence_review_meters' => $this->geofenceReviewMeters(),
        ]));

        return $event;
    }

    /**
     * @param  array{latitude: float|int|string, longitude: float|int|string, accuracy_meters?: float|int|string|null}|string|null  $locationOrManualReason
     * @return array{latitude: float, longitude: float, accuracy_meters: float|null}|null
     */
    private function locationPayload(array|string|null $locationOrManualReason): ?array
    {
        if (! is_array($locationOrManualReason)) {
            return null;
        }

        foreach (['latitude', 'longitude'] as $required) {
            if (! array_key_exists($required, $locationOrManualReason) || trim((string) $locationOrManualReason[$required]) === '') {
                throw new InvalidArgumentException("GPS {$required} is required.");
            }
        }

        return [
            'latitude' => (float) $locationOrManualReason['latitude'],
            'longitude' => (float) $locationOrManualReason['longitude'],
            'accuracy_meters' => array_key_exists('accuracy_meters', $locationOrManualReason)
                && $locationOrManualReason['accuracy_meters'] !== null
                ? (float) $locationOrManualReason['accuracy_meters']
                : null,
        ];
    }

    /**
     * @param  array{latitude: float, longitude: float, accuracy_meters: float|null}  $location
     */
    private function distanceFromPlannedAddress(Visit $visit, array $location): ?float
    {
        if ($visit->planned_visit_id === null) {
            return null;
        }

        $plannedVisit = PlannedVisit::query()->whereKey($visit->planned_visit_id)->first();

        if ($plannedVisit === null || $plannedVisit->location_latitude === null || $plannedVisit->location_longitude === null) {
            return null;
        }

        $row = DB::selectOne(
            'select ST_Distance_Sphere(ST_GeomFromText(?, 4326), ST_GeomFromText(?, 4326)) as distance_meters',
            [
                sprintf('POINT(%F %F)', $location['longitude'], $location['latitude']),
                sprintf('POINT(%F %F)', (float) $plannedVisit->location_longitude, (float) $plannedVisit->location_latitude),
            ],
        );

        return $row !== null ? round((float) $row->distance_meters, 2) : null;
    }

    private function geofenceReviewMeters(): int
    {
        return (int) $this->settings->get('nursing.visit.geofence_review_meters', self::GEOFENCE_REVIEW_METERS_DEFAULT);
    }

    private function ensurePrivacyNoticeStored(): void
    {
        if (! Setting::query()->where('key', self::PRIVACY_NOTICE_SETTING_KEY)->exists()) {
            $this->settings->set(self::PRIVACY_NOTICE_SETTING_KEY, self::PRIVACY_NOTICE_TEXT, 'string');
        }
    }

    private function assertSameTenant(object $model, string $attribute): void
    {
        if (($model->tenant_id ?? null) !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, (string) ($model->id ?? ''));
        }
    }
}
