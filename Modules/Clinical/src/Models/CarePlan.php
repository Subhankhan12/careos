<?php

namespace Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $title
 * @property string $status
 * @property Carbon $started_on
 * @property Carbon|null $ended_on
 * @property string $created_by
 */
class CarePlan extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'title',
        'status',
        'started_on',
        'ended_on',
        'created_by',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected static function booted(): void
    {
        static::creating(fn (CarePlan $carePlan) => $carePlan->assertTenantReferences());
        static::updating(function (CarePlan $carePlan): void {
            if ($carePlan->isDirty(['patient_id', 'created_by'])) {
                $carePlan->assertTenantReferences();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_on' => 'date',
            'ended_on' => 'date',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'created_by');
    }

    public function goals(): HasMany
    {
        return $this->hasMany(CarePlanGoal::class);
    }

    protected function auditResourceType(): string
    {
        return 'care_plan';
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }

    private function assertTenantReferences(): void
    {
        if (! Patient::query()->whereKey($this->patient_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $this->patient_id);
        }

        if (! StaffProfile::query()->whereKey($this->created_by)->exists()) {
            throw CrossTenantReferenceException::forAttribute('created_by', (string) $this->created_by);
        }
    }
}
