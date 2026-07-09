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
 * @property string|null $encounter_id
 * @property string $description
 * @property string|null $code
 * @property Carbon|null $onset_date
 * @property string $status
 * @property string $recorded_by
 * @property Carbon $recorded_at
 * @property Carbon|null $resolved_at
 */
class Problem extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_INACTIVE = 'inactive';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'encounter_id',
        'description',
        'code',
        'onset_date',
        'status',
        'recorded_by',
        'recorded_at',
        'resolved_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected static function booted(): void
    {
        static::creating(fn (Problem $problem) => $problem->assertTenantReferences());
        static::updating(function (Problem $problem): void {
            if ($problem->isDirty(['patient_id', 'encounter_id', 'recorded_by'])) {
                $problem->assertTenantReferences();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'onset_date' => 'date',
            'recorded_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'recorded_by');
    }

    protected function auditResourceType(): string
    {
        return 'problem';
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

        if ($this->encounter_id !== null) {
            $encounter = Encounter::query()->whereKey($this->encounter_id)->first();
            if ($encounter === null || $encounter->patient_id !== $this->patient_id) {
                throw CrossTenantReferenceException::forAttribute('encounter_id', (string) $this->encounter_id);
            }
        }

        if (! StaffProfile::query()->whereKey($this->recorded_by)->exists()) {
            throw CrossTenantReferenceException::forAttribute('recorded_by', (string) $this->recorded_by);
        }
    }
}
