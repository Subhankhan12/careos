<?php

namespace Modules\Nursing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * Raw vitals recorded during a visit. No interpretation, ranges, flags, scores,
 * or derived fields belong in this model.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $visit_id
 * @property string $patient_id
 * @property Carbon $recorded_at
 * @property int|null $systolic
 * @property int|null $diastolic
 * @property int|null $heart_rate
 * @property string|null $temperature_c
 * @property int|null $spo2
 * @property int|null $weight_g
 * @property int|null $height_mm
 * @property array<string, mixed>|null $extra
 */
class VisitVital extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'patient_id',
        'recorded_at',
        'systolic',
        'diastolic',
        'heart_rate',
        'temperature_c',
        'spo2',
        'weight_g',
        'height_mm',
        'extra',
    ];

    protected static function booted(): void
    {
        static::saving(fn (VisitVital $vital) => $vital->assertTenantReferences());
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

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    private function assertTenantReferences(): void
    {
        $visit = Visit::query()->whereKey($this->visit_id)->first();
        if ($visit === null || $visit->patient_id !== $this->patient_id) {
            throw CrossTenantReferenceException::forAttribute('visit_id', (string) $this->visit_id);
        }

        if (! Patient::query()->whereKey($this->patient_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $this->patient_id);
        }
    }
}
