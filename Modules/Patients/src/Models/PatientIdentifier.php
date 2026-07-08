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
 * @property string $system
 * @property string $value
 */
class PatientIdentifier extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'system',
        'value',
        'valid_from',
        'valid_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (PatientIdentifier $identifier) => $identifier->assertPatientWithinTenant());
        static::updating(function (PatientIdentifier $identifier): void {
            if ($identifier->isDirty('patient_id')) {
                $identifier->assertPatientWithinTenant();
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
