<?php

namespace Modules\Scheduling\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Modules\Platform\Models\User;
use Modules\Platform\Services\SettingsService;
use Modules\Scheduling\Events\WaitlistEntryStatusChanged;
use Modules\Scheduling\Events\WaitlistOfferLifecycleChanged;
use Modules\Scheduling\Exceptions\WaitlistException;
use Modules\Scheduling\Models\Appointment;
use Modules\Scheduling\Models\WaitlistEntry;
use Modules\Scheduling\Models\WaitlistOffer;

/**
 * The reception auto-fill workflow: when a slot frees, surface matching waitlist
 * entries, offer the slot (time-boxed), notify the patient, and book on accept
 * through the SAFE BookingService path.
 *
 * Boundary: this service never touches Comms. The offer notification is composed
 * in the app layer, which listens to WaitlistOfferLifecycleChanged and calls the
 * Comms NotificationService (Scheduling may not depend on Comms).
 */
class WaitlistOfferService
{
    /** Default hold on a freed slot before it can be offered elsewhere. */
    public const DEFAULT_TTL_MINUTES = 30;

    public function __construct(
        private readonly WaitlistService $waitlist,
        private readonly BookingService $bookings,
        private readonly SettingsService $settings,
    ) {}

    public function ttlMinutes(): int
    {
        return (int) $this->settings->get('scheduling.waitlist.offer_ttl_minutes', self::DEFAULT_TTL_MINUTES);
    }

    /**
     * Matching waitlist entries for a freed slot, ranked (priority desc, oldest
     * first — the WaitlistService ordering).
     *
     * @return Collection<int, WaitlistEntry>
     */
    public function candidates(
        string $serviceId,
        string $branchId,
        CarbonInterface|string $startsAt,
        CarbonInterface|string $endsAt,
        User $actor,
    ): Collection {
        $this->authorize($branchId, $actor);

        return $this->waitlist->matchingForSlot($serviceId, $branchId, $startsAt, $endsAt);
    }

    /**
     * Offer a freed slot to one waitlist entry. Creates a time-boxed offer and
     * fires the lifecycle event (app layer audits + notifies the patient).
     *
     * @param  list<string>  $resourceIds
     */
    public function offer(
        WaitlistEntry $entry,
        string $branchId,
        CarbonInterface|string $startsAt,
        CarbonInterface|string $endsAt,
        array $resourceIds,
        User $actor,
        ?string $sourceAppointmentId = null,
    ): WaitlistOffer {
        $this->authorize($branchId, $actor);

        $starts = CarbonImmutable::parse($startsAt);
        $ends = CarbonImmutable::parse($endsAt);

        if (! $this->waitlist->matchingForSlot($entry->service_id, $branchId, $starts, $ends)->contains('id', $entry->id)) {
            throw WaitlistException::slotDoesNotMatch();
        }

        // One open offer per entry at a time.
        $openExists = WaitlistOffer::query()
            ->where('waitlist_entry_id', $entry->id)
            ->whereIn('status', WaitlistOffer::OPEN_STATUSES)
            ->exists();

        if ($openExists) {
            throw WaitlistException::alreadyOffered();
        }

        $now = Carbon::now();
        $offer = WaitlistOffer::query()->create([
            'waitlist_entry_id' => $entry->id,
            'source_appointment_id' => $sourceAppointmentId,
            'patient_id' => $entry->patient_id,
            'service_id' => $entry->service_id,
            'branch_id' => $branchId,
            'slot_starts_at' => $starts,
            'slot_ends_at' => $ends,
            'resource_ids' => array_values(array_unique($resourceIds)),
            'status' => WaitlistOffer::STATUS_OFFERED,
            'offered_by' => (string) $actor->getKey(),
            'offered_at' => $now,
            'expires_at' => $now->copy()->addMinutes($this->ttlMinutes()),
        ]);

        Event::dispatch(new WaitlistOfferLifecycleChanged(
            $offer->refresh(),
            null,
            WaitlistOffer::STATUS_OFFERED,
            $actor,
            ['expires_at' => $offer->expires_at->toDateTimeString()],
        ));

        return $offer;
    }

    /**
     * Accept an offer: book through the SAFE BookingService path (no-double-book
     * via resource row locks) and mark the entry booked. Two concurrent accepts
     * of the same freed slot resolve to exactly one appointment (the loser hits
     * a BookingConflictException from BookingService).
     */
    public function accept(WaitlistOffer $offer, User $actor): Appointment
    {
        $this->authorize($offer->branch_id, $actor);

        $offer->refresh();

        if ($offer->status !== WaitlistOffer::STATUS_OFFERED) {
            throw WaitlistException::offerNotOpen($offer->status);
        }

        // TTL is time-based, so check (and record) expiry OUTSIDE the booking
        // transaction — otherwise the throw would roll the expiry back.
        if ($offer->expires_at->lessThanOrEqualTo(Carbon::now())) {
            $this->expire($offer);

            throw WaitlistException::offerExpired();
        }

        /** @var array{offer: WaitlistOffer, appointment: Appointment, entry: WaitlistEntry} $result */
        $result = DB::transaction(function () use ($offer, $actor): array {
            $locked = WaitlistOffer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== WaitlistOffer::STATUS_OFFERED) {
                throw WaitlistException::offerNotOpen($locked->status);
            }

            $appointment = $this->bookings->book(
                $locked->service_id,
                $locked->patient_id,
                $locked->branch_id,
                $locked->slot_starts_at,
                $locked->resource_ids,
                $actor,
            );

            $locked->forceFill([
                'status' => WaitlistOffer::STATUS_ACCEPTED,
                'responded_at' => Carbon::now(),
                'booked_appointment_id' => $appointment->id,
            ])->save();

            $entry = WaitlistEntry::query()->whereKey($locked->waitlist_entry_id)->firstOrFail();
            $entry->forceFill(['status' => WaitlistEntry::STATUS_BOOKED])->save();

            return ['offer' => $locked->refresh(), 'appointment' => $appointment, 'entry' => $entry->refresh()];
        });

        Event::dispatch(new WaitlistOfferLifecycleChanged(
            $result['offer'],
            WaitlistOffer::STATUS_OFFERED,
            WaitlistOffer::STATUS_ACCEPTED,
            $actor,
            ['appointment_id' => $result['appointment']->id],
        ));

        // Reuse the existing waitlist-entry audit path for the entry transition.
        Event::dispatch(new WaitlistEntryStatusChanged(
            $result['entry'],
            WaitlistEntry::STATUS_WAITING,
            WaitlistEntry::STATUS_BOOKED,
            $actor,
            ['appointment_id' => $result['appointment']->id, 'offer_id' => $result['offer']->id],
        ));

        return $result['appointment'];
    }

    /**
     * Decline an offer. The waitlist entry stays 'waiting' and the freed slot can
     * be offered to the next matching candidate.
     */
    public function decline(WaitlistOffer $offer, User $actor): WaitlistOffer
    {
        $this->authorize($offer->branch_id, $actor);

        return $this->close($offer, WaitlistOffer::STATUS_DECLINED, $actor);
    }

    /** Expire a single offer (system action — no human actor). */
    public function expire(WaitlistOffer $offer): WaitlistOffer
    {
        return $this->close($offer, WaitlistOffer::STATUS_EXPIRED, null);
    }

    /**
     * Sweep offers past their TTL and expire them. Returns the number expired.
     */
    public function expireDue(?CarbonInterface $asOf = null): int
    {
        $asOf = $asOf !== null ? Carbon::parse($asOf) : Carbon::now();
        $count = 0;

        WaitlistOffer::query()
            ->where('status', WaitlistOffer::STATUS_OFFERED)
            ->where('expires_at', '<=', $asOf)
            ->orderBy('id')
            ->each(function (WaitlistOffer $offer) use (&$count): void {
                $this->expire($offer);
                $count++;
            });

        return $count;
    }

    private function close(WaitlistOffer $offer, string $toStatus, ?User $actor): WaitlistOffer
    {
        /** @var WaitlistOffer $closed */
        $closed = DB::transaction(function () use ($offer, $toStatus): WaitlistOffer {
            $locked = WaitlistOffer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== WaitlistOffer::STATUS_OFFERED) {
                throw WaitlistException::offerNotOpen($locked->status);
            }

            $locked->forceFill([
                'status' => $toStatus,
                'responded_at' => Carbon::now(),
            ])->save();

            return $locked->refresh();
        });

        Event::dispatch(new WaitlistOfferLifecycleChanged(
            $closed,
            WaitlistOffer::STATUS_OFFERED,
            $toStatus,
            $actor,
        ));

        return $closed;
    }

    private function authorize(string $branchId, User $actor): void
    {
        if (! Gate::forUser($actor)->allows('appointment.manage', ['branch_id' => $branchId])) {
            throw new AuthorizationException('This user cannot manage appointments.');
        }
    }
}
