<?php

namespace Modules\Patients\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $payer_name
 * @property string $member_id
 * @property string|null $plan
 * @property string $coverage_type
 * @property int $priority
 */
class PatientCoverage extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'payer_name',
        'member_id',
        'plan',
        'coverage_type',
        'priority',
        'valid_from',
        'valid_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (PatientCoverage $coverage) => $coverage->assertPatientWithinTenant());
        static::updating(function (PatientCoverage $coverage): void {
            if ($coverage->isDirty('patient_id')) {
                $coverage->assertPatientWithinTenant();
            }
        });
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    private function assertPatientWithinTenant(): void
    {
        if (! Patient::whereKey($this->patient_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $this->patient_id);
        }
    }
}
