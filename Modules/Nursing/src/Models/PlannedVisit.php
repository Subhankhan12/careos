<?php

namespace Modules\Nursing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Scheduling\Models\Resource;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $visit_plan_id
 * @property string $patient_id
 * @property Carbon $scheduled_date
 * @property Carbon $window_start_at
 * @property Carbon $window_end_at
 * @property int $duration_minutes
 * @property string|null $required_qualification
 * @property string $status
 * @property string|null $assigned_resource_id
 * @property string|null $cancellation_reason
 */
class PlannedVisit extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_SKIPPED = 'skipped';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'visit_plan_id',
        'patient_id',
        'scheduled_date',
        'window_start_at',
        'window_end_at',
        'duration_minutes',
        'required_qualification',
        'status',
        'assigned_resource_id',
        'cancellation_reason',
    ];

    protected $attributes = [
        'status' => self::STATUS_PLANNED,
    ];

    protected static function booted(): void
    {
        static::creating(fn (PlannedVisit $visit) => $visit->assertTenantReferences());
        static::updating(function (PlannedVisit $visit): void {
            if ($visit->isDirty(['visit_plan_id', 'patient_id', 'assigned_resource_id'])) {
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
            'scheduled_date' => 'date',
            'window_start_at' => 'datetime',
            'window_end_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
    }

    public function visitPlan(): BelongsTo
    {
        return $this->belongsTo(VisitPlan::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function assignedResource(): BelongsTo
    {
        return $this->belongsTo(Resource::class, 'assigned_resource_id');
    }

    private function assertTenantReferences(): void
    {
        $visitPlan = VisitPlan::query()->whereKey($this->visit_plan_id)->first();

        if ($visitPlan === null) {
            throw CrossTenantReferenceException::forAttribute('visit_plan_id', (string) $this->visit_plan_id);
        }

        if (! Patient::query()->whereKey($this->patient_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $this->patient_id);
        }

        if ($this->assigned_resource_id !== null && ! Resource::query()->whereKey($this->assigned_resource_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute(
                'assigned_resource_id',
                (string) $this->assigned_resource_id,
            );
        }
    }
}
