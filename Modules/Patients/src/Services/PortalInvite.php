<?php

namespace Modules\Patients\Services;

use Modules\Patients\Models\PortalAccount;
use Modules\Patients\Models\PortalLoginToken;

readonly class PortalInvite
{
    public function __construct(
        public PortalAccount $account,
        public PortalLoginToken $loginToken,
        public string $plainToken,
        public string $otp,
    ) {}
}
