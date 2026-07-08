<?php

namespace Modules\Audit\Facades;

use Illuminate\Support\Facades\Facade;
use Modules\Audit\Models\AuditEvent;
use Modules\Audit\Services\AuditService;

/**
 * @method static AuditEvent record(array<string, mixed> $data)
 * @method static AuditEvent recordRead(string $resourceType, string $resourceId, ?string $patientId = null, array<string, mixed> $context = [])
 * @method static array{ok: bool, broken_at: string|null, index?: int, reason?: string, count?: int} verifyChain(?string $tenantId = null)
 *
 * @see AuditService
 */
class Audit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AuditService::class;
    }
}
