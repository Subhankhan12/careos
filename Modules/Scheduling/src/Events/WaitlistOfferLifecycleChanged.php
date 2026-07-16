<?php

namespace Modules\Scheduling\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Platform\Models\User;
use Modules\Scheduling\Models\WaitlistOffer;

/**
 * A waitlist offer changed state. The app layer listens to audit every change
 * and — only on creation (toStatus = offered) — to notify the patient through
 * the Comms NotificationService (Scheduling may not depend on Comms).
 *
 * `actor` is null when the change is a system action (e.g. TTL expiry sweep).
 */
class WaitlistOfferLifecycleChanged
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly WaitlistOffer $offer,
        public readonly ?string $fromStatus,
        public readonly string $toStatus,
        public readonly ?User $actor,
        public readonly array $context = [],
    ) {}
}
