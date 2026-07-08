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
 * @property string $type
 * @property string|null $value
 * @property string|null $line1
 * @property string|null $line2
 * @property string|null $city
 * @property string|null $postal
 * @property string|null $country
 * @property bool $is_primary
 */
class PatientContact extends Model
{
    use BelongsToTenant, HasUlids;

    public const TYPE_PHONE = 'phone';

    public const TYPE_EMAIL = 'email';

    public const TYPE_ADDRESS = 'address';

    public const TYPE_EMERGENCY = 'emergency';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'type',
        'value',
        'line1',
        'line2',
        'city',
        'postal',
        'country',
        'is_primary',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (PatientContact $contact) => $contact->assertPatientWithinTenant());
        static::updating(function (PatientContact $contact): void {
            if ($contact->isDirty('patient_id')) {
                $contact->assertPatientWithinTenant();
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
