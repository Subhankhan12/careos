<?php

namespace Modules\Audit\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Audit\Exceptions\AuditEventImmutableException;

/**
 * Read-only view over the append-only audit_events table.
 *
 * Writes happen through the AuditService (raw insert inside the hash-chain
 * transaction), never through this model. Update/delete are blocked here (and
 * authoritatively by DB triggers).
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string $action
 * @property string|null $resource_type
 * @property string|null $resource_id
 * @property string|null $patient_id
 * @property array<string, mixed>|null $context
 * @property string $occurred_at
 * @property string|null $prev_hash
 * @property string $hash
 */
class AuditEvent extends Model
{
    protected $table = 'audit_events';

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw AuditEventImmutableException::make();
        });

        static::deleting(function (): void {
            throw AuditEventImmutableException::make();
        });
    }
}
