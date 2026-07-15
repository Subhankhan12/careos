<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * Tenant-owned, append-only result of a scheduled integrity check.
 *
 * Lives in Platform rather than Audit for a boundary reason: the row is
 * TENANT-OWNED, so it needs {@see BelongsToTenant}, and Audit may not depend on
 * Platform. Platform does not depend on Audit either, so the row stays a plain
 * tenant-scoped fact and the app layer composes the two.
 *
 * Append-only at model and DB-trigger level: the result of a check is evidence,
 * and evidence that can be rewritten afterwards is not evidence.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $kind
 * @property Carbon $checked_at
 * @property bool $ok
 * @property array<string, mixed>|null $detail
 */
class IntegrityCheck extends Model
{
    use BelongsToTenant, HasUlids;

    public const KIND_AUDIT_CHAIN = 'audit_chain';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'kind',
        'checked_at',
        'ok',
        'detail',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('integrity_checks are append-only: they cannot be updated.');
        });
        static::deleting(function (): void {
            throw new LogicException('integrity_checks are append-only: they cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'ok' => 'boolean',
            'detail' => 'array',
        ];
    }
}
