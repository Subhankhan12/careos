<?php

namespace Modules\Nursing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Scheduling\Models\Resource;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $planned_visit_id
 * @property string $patient_id
 * @property string $resource_id
 * @property string $branch_id
 * @property Carbon $scheduled_start_at
 * @property Carbon|null $checked_in_at
 * @property Carbon|null $checked_out_at
 * @property string $status
 * @property string $client_visit_uuid
 */
class Visit extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_MISSED = 'missed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_MISSED,
        self::STATUS_CANCELLED,
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'planned_visit_id',
        'patient_id',
        'resource_id',
        'branch_id',
        'scheduled_start_at',
        'checked_in_at',
        'checked_out_at',
        'status',
        'client_visit_uuid',
    ];

    protected $attributes = [
        'status' => self::STATUS_SCHEDULED,
    ];

    protected static function booted(): void
    {
        static::creating(fn (Visit $visit) => $visit->assertTenantReferences());
        static::updating(function (Visit $visit): void {
            if ($visit->isDirty(['planned_visit_id', 'patient_id', 'resource_id', 'branch_id'])) {
                $visit->assertTenantReferences();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_start_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
        ];
    }

    public function plannedVisit(): BelongsTo
    {
        return $this->belongsTo(PlannedVisit::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(VisitEvent::class);
    }

    private function assertTenantReferences(): void
    {
        if ($this->planned_visit_id !== null && ! PlannedVisit::query()->whereKey($this->planned_visit_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('planned_visit_id', (string) $this->planned_visit_id);
        }

        if (! Patient::query()->whereKey($this->patient_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $this->patient_id);
        }

        if (! Resource::query()->whereKey($this->resource_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('resource_id', (string) $this->resource_id);
        }

        if (! Branch::query()->whereKey($this->branch_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('branch_id', (string) $this->branch_id);
        }
    }
}
