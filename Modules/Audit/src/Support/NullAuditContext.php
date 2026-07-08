<?php

namespace Modules\Audit\Support;

use Modules\Audit\Contracts\AuditContext;

/**
 * Default {@see AuditContext} used when the application has not bound a
 * richer implementation — everything is a context-less platform/service event.
 */
class NullAuditContext implements AuditContext
{
    public function tenantId(): ?string
    {
        return null;
    }

    public function actor(): array
    {
        return ['type' => 'service', 'id' => null];
    }

    public function ip(): ?string
    {
        return null;
    }

    public function userAgent(): ?string
    {
        return null;
    }
}
