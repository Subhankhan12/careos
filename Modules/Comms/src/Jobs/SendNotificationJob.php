<?php

namespace Modules\Comms\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Comms\Models\NotificationDelivery;
use Modules\Comms\Services\NotificationService;
use Modules\Patients\Models\Patient;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $templateKey,
        public readonly string $recipientType,
        public readonly string $recipientId,
        public readonly array $context,
        public readonly ?string $expectedCategory,
    ) {}

    public function handle(TenantContext $tenants, NotificationService $notifications): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $previousTenant = $tenants->current();
        $tenants->set($tenant);

        try {
            $recipient = $this->recipientType === NotificationDelivery::RECIPIENT_PATIENT
                ? Patient::query()->findOrFail($this->recipientId)
                : User::query()->findOrFail((int) $this->recipientId);

            // send() is dedupe-keyed, so a Horizon retry never double-sends.
            $notifications->send($this->templateKey, $recipient, $this->context, $this->expectedCategory);
        } finally {
            if ($previousTenant !== null) {
                $tenants->set($previousTenant);
            } else {
                $tenants->forget();
            }
        }
    }
}
