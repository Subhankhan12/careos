<?php

namespace Modules\Hospital\Events;

use Modules\Hospital\Models\Bed;
use Modules\Platform\Models\User;

/**
 * Fired by BedService whenever a bed's housekeeping status transitions (including
 * the concurrency-safe claim, free -> occupied). The app layer listens and writes
 * one append-only audit row per transition — the same pattern as
 * Scheduling\Events\AppointmentTransitioned — so Hospital never depends on Audit.
 */
class BedStatusChanged
{
    public function __construct(
        public readonly Bed $bed,
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly User $actor,
        public readonly ?string $reason = null,
    ) {}
}
