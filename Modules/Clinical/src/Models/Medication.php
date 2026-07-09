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
 * @property string $name
 * @property string $substance_key
 * @property string|null $dose_text
 * @property string|null $route
 * @property string|null $frequency_text
 * @property Carbon $started_on
 * @property Carbon|null $ended_on
 * @property string $status
 * @property string $recorded_by
 * @property Carbon $recorded_at
 */
class Medication extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_STOPPED = 'stopped';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'name',
        'substance_key',
        'dose_text',
        'route',
        'frequency_text',
        'started_on',
        'ended_on',
        'status',
        'recorded_by',
        'recorded_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected static function booted(): void
    {
        static::creating(fn (Medication $medication) => $medication->assertTenantReferences());
        static::updating(function (Medication $medication): void {
            if ($medication->isDirty(['patient_id', 'recorded_by'])) {
                $medication->assertTenantReferences();
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
            'recorded_at' => 'datetime',
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
        return 'medication';
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
