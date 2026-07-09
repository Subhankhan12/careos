<?php

namespace Modules\Nursing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\User;
use Modules\Scheduling\Models\Resource;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $resource_id
 * @property string $visit_id
 * @property Carbon $date
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property int|null $minutes
 * @property int|null $travel_minutes
 * @property array<int, string>|null $discrepancy_flags
 * @property string $status
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 */
class TimesheetLine extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const FLAG_MISSING_CHECK_OUT = 'missing_check_out';

    public const FLAG_MANUAL_LOCATION = 'manual_location';

    public const FLAG_DURATION_DEVIATION = 'duration_deviation';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'resource_id',
        'visit_id',
        'date',
        'started_at',
        'ended_at',
        'minutes',
        'travel_minutes',
        'discrepancy_flags',
        'status',
        'approved_by',
        'approved_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected static function booted(): void
    {
        static::creating(fn (TimesheetLine $line) => $line->assertTenantReferences());
        static::updating(function (TimesheetLine $line): void {
            if ($line->getOriginal('status') === self::STATUS_APPROVED) {
                throw new LogicException('Approved timesheet lines are immutable.');
            }

            if ($line->isDirty(['resource_id', 'visit_id', 'approved_by'])) {
                $line->assertTenantReferences();
            }
        });
        static::deleting(function (TimesheetLine $line): void {
            if ($line->status === self::STATUS_APPROVED) {
                throw new LogicException('Approved timesheet lines cannot be deleted.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'minutes' => 'integer',
            'travel_minutes' => 'integer',
            'discrepancy_flags' => 'array',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    private function assertTenantReferences(): void
    {
        $visit = Visit::query()->whereKey($this->visit_id)->first();
        if ($visit === null) {
            throw CrossTenantReferenceException::forAttribute('visit_id', (string) $this->visit_id);
        }

        if ($visit->resource_id !== $this->resource_id) {
            throw CrossTenantReferenceException::forAttribute('resource_id', (string) $this->resource_id);
        }

        if (! Resource::query()->whereKey($this->resource_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('resource_id', (string) $this->resource_id);
        }

        if ($this->approved_by !== null && ! User::query()
            ->whereKey($this->approved_by)
            ->where('tenant_id', $this->tenant_id)
            ->exists()) {
            throw CrossTenantReferenceException::forAttribute('approved_by', (string) $this->approved_by);
        }
    }
}
