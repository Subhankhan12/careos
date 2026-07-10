<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * Tenant-owned append-only monthly-close artifact: the result of running the
 * reconciliation engine for a period. Immutable at model and DB-trigger levels.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $period
 * @property Carbon $ran_at
 * @property bool $passed
 * @property array<string, mixed> $report
 * @property int $ran_by
 */
class ReconciliationRun extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'period',
        'ran_at',
        'passed',
        'report',
        'ran_by',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('reconciliation_runs are append-only: they cannot be updated.');
        });
        static::deleting(function (): void {
            throw new LogicException('reconciliation_runs are append-only: they cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ran_at' => 'datetime',
            'passed' => 'boolean',
            'report' => 'array',
        ];
    }
}
