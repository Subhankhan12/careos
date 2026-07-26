<?php

namespace Modules\Hospital\Events;

use Modules\Hospital\Models\Stay;
use Modules\Platform\Models\User;

/**
 * Fired by AdmissionService on every ADT transition (admit / transfer / discharge). The app
 * layer listens and writes one append-only `admission.<eventType>` audit row — the same
 * pattern as Scheduling\Events\AppointmentTransitioned — so Hospital never depends on Audit.
 * `eventType` (not the stay status) names the action, since a transfer keeps status = admitted.
 *
 * @param  array<string, mixed>  $context
 */
class StayTransitioned
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly Stay $stay,
        public readonly ?string $fromStatus,
        public readonly string $toStatus,
        public readonly User $actor,
        public readonly string $eventType,
        public readonly ?string $reason = null,
        public readonly array $context = [],
    ) {}
}
