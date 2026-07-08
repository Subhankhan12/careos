<?php

namespace Modules\Audit\Contracts;

/**
 * Ambient context the AuditService uses to stamp who/where an event came from.
 *
 * Audit owns this interface so it never depends on the Platform module. The
 * Platform-aware implementation (reading TenantContext + the auth guard) is
 * bound in the application layer.
 */
interface AuditContext
{
    /**
     * The current tenant id, or null for platform-level events.
     */
    public function tenantId(): ?string;

    /**
     * The current actor as ['type' => 'user'|'service'|'ai', 'id' => ?string].
     *
     * @return array{type: string, id: string|null}
     */
    public function actor(): array;

    /**
     * The request IP, or null (e.g. console).
     */
    public function ip(): ?string;

    /**
     * The request user agent, or null.
     */
    public function userAgent(): ?string;
}
