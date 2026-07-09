<?php

namespace Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $care_plan_id
 * @property string $description
 * @property Carbon|null $target_date
 * @property string $status
 */
class CarePlanGoal extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_OPEN = 'open';

    public const STATUS_MET = 'met';

    public const STATUS_NOT_MET = 'not_met';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'care_plan_id',
        'description',
        'target_date',
        'status',
    ];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
    ];

    protected static function booted(): void
    {
        static::creating(fn (CarePlanGoal $goal) => $goal->assertTenantReferences());
        static::updating(function (CarePlanGoal $goal): void {
            if ($goal->isDirty(['care_plan_id'])) {
                $goal->assertTenantReferences();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_date' => 'date',
        ];
    }

    public function carePlan(): BelongsTo
    {
        return $this->belongsTo(CarePlan::class);
    }

    private function assertTenantReferences(): void
    {
        if (! CarePlan::query()->whereKey($this->care_plan_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('care_plan_id', (string) $this->care_plan_id);
        }
    }
}
