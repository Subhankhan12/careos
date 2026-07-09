<?php

namespace Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property string $substance
 * @property string $substance_key
 * @property string|null $reaction
 * @property string $severity
 * @property string $status
 * @property string $recorded_by
 * @property Carbon $recorded_at
 * @property Carbon|null $verified_at
 */
class Allergy extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const SEVERITY_MILD = 'mild';

    public const SEVERITY_MODERATE = 'moderate';

    public const SEVERITY_SEVERE = 'severe';

    public const SEVERITY_UNKNOWN = 'unknown';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'substance',
        'substance_key',
        'reaction',
        'severity',
        'status',
        'recorded_by',
        'recorded_at',
        'verified_at',
    ];

    protected $attributes = [
        'severity' => self::SEVERITY_UNKNOWN,
        'status' => self::STATUS_ACTIVE,
    ];

    protected static function booted(): void
    {
        static::creating(fn (Allergy $allergy) => $allergy->assertTenantReferences());
        static::updating(function (Allergy $allergy): void {
            if ($allergy->isDirty(['patient_id', 'recorded_by'])) {
                $allergy->assertTenantReferences();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'recorded_by');
    }

    protected function auditResourceType(): string
    {
        return 'allergy';
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

        if (! StaffProfile::query()->whereKey($this->recorded_by)->exists()) {
            throw CrossTenantReferenceException::forAttribute('recorded_by', (string) $this->recorded_by);
        }
    }
}
