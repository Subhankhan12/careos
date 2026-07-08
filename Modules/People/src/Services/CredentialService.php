<?php

namespace Modules\People\Services;

use Illuminate\Support\Carbon;
use Modules\People\Models\Credential;
use Modules\Platform\Services\SettingsService;

class CredentialService
{
    public const EXPIRY_WINDOW_SETTING = 'people.credentials.expiry_alert_days';

    public const DEFAULT_EXPIRY_WINDOW_DAYS = 30;

    public function __construct(private readonly SettingsService $settings) {}

    public function expiringWindowDays(): int
    {
        $value = $this->settings->get(self::EXPIRY_WINDOW_SETTING, self::DEFAULT_EXPIRY_WINDOW_DAYS);

        return max(0, (int) $value);
    }

    public function statusFor(mixed $expiresOn, ?string $currentStatus = null, ?int $windowDays = null): string
    {
        if ($currentStatus === Credential::STATUS_REVOKED) {
            return Credential::STATUS_REVOKED;
        }

        if ($expiresOn === null || $expiresOn === '') {
            return Credential::STATUS_VALID;
        }

        $expires = Carbon::parse($expiresOn)->startOfDay();
        $today = Carbon::today();

        if ($expires->lt($today)) {
            return Credential::STATUS_EXPIRED;
        }

        $windowDays ??= $this->expiringWindowDays();

        if ($expires->lte($today->copy()->addDays($windowDays))) {
            return Credential::STATUS_EXPIRING;
        }

        return Credential::STATUS_VALID;
    }

    public function refreshStatus(Credential $credential): bool
    {
        $status = $this->statusFor($credential->expires_on, $credential->status);

        if ($credential->status === $status) {
            return false;
        }

        $credential->status = $status;
        $credential->save();

        return true;
    }
}
