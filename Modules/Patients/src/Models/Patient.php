<?php

namespace Modules\Patients\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * The tenant-owned patient CRM record.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $mrn
 * @property string $first_name
 * @property string $last_name
 * @property Carbon $date_of_birth
 * @property string $sex
 * @property string|null $gender
 * @property string|null $preferred_language
 * @property Carbon|null $deceased_at
 * @property string $status
 * @property string|null $merged_into_id
 * @property Carbon|null $deleted_at
 */
class Patient extends Model
{
    use BelongsToTenant, HasUlids, LogsReads, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_MERGED = 'merged';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'mrn',
        'first_name',
        'last_name',
        'date_of_birth',
        'sex',
        'gender',
        'preferred_language',
        'deceased_at',
        'status',
        'merged_into_id',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'deceased_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Patient $patient): void {
            $patient->assertMergedIntoWithinTenant();
        });

        static::updating(function (Patient $patient): void {
            if ($patient->isDirty('merged_into_id')) {
                $patient->assertMergedIntoWithinTenant();
            }
        });
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(PatientContact::class);
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(PatientIdentifier::class);
    }

    public function coverages(): HasMany
    {
        return $this->hasMany(PatientCoverage::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(PatientConsent::class);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    protected function auditResourceType(): string
    {
        return 'patient';
    }

    protected function auditPatientId(): ?string
    {
        return $this->id;
    }

    private function assertMergedIntoWithinTenant(): void
    {
        if (empty($this->merged_into_id)) {
            return;
        }

        if (! self::whereKey($this->merged_into_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('merged_into_id', (string) $this->merged_into_id);
        }
    }
}
