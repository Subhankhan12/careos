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
 * @property Carbon $recorded_at
 * @property int|null $systolic
 * @property int|null $diastolic
 * @property int|null $heart_rate
 * @property string|null $temperature_c
 * @property int|null $spo2
 * @property int|null $weight_g
 * @property int|null $height_mm
 * @property array<string, mixed>|null $extra
 * @property string $recorded_by
 */
class Vital extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'encounter_id',
        'recorded_at',
        'systolic',
        'diastolic',
        'heart_rate',
        'temperature_c',
        'spo2',
        'weight_g',
        'height_mm',
        'extra',
        'recorded_by',
    ];

    protected static function booted(): void
    {
        static::creating(fn (Vital $vital) => $vital->assertTenantReferences());
        static::updating(function (Vital $vital): void {
            if ($vital->isDirty(['patient_id', 'encounter_id', 'recorded_by'])) {
                $vital->assertTenantReferences();
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
            'temperature_c' => 'decimal:1',
            'extra' => 'array',
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
        return 'vital';
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
