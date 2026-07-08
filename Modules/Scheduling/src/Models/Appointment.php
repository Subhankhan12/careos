<?php

namespace Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;

/**
 * Tenant-owned appointment booking.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $patient_id
 * @property string $service_id
 * @property string $branch_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $status
 * @property string|null $booked_by
 * @property string $source
 * @property string|null $notes
 */
class Appointment extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_BOOKED = 'booked';

    public const SOURCE_STAFF = 'staff';

    public const SOURCE_ONLINE = 'online';

    public const SOURCE_AGENT = 'agent';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'service_id',
        'branch_id',
        'starts_at',
        'ends_at',
        'status',
        'booked_by',
        'source',
        'notes',
    ];

    protected $attributes = [
        'status' => self::STATUS_BOOKED,
        'source' => self::SOURCE_STAFF,
    ];

    protected static function booted(): void
    {
        static::creating(function (Appointment $appointment): void {
            $appointment->assertTenantReferences();
        });

        static::updating(function (Appointment $appointment): void {
            if ($appointment->isDirty(['patient_id', 'service_id', 'branch_id'])) {
                $appointment->assertTenantReferences();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function resourceLinks(): HasMany
    {
        return $this->hasMany(AppointmentResource::class);
    }

    public function resources(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'appointment_resources')
            ->withPivot('tenant_id')
            ->withTimestamps();
    }

    private function assertTenantReferences(): void
    {
        if (! Service::whereKey($this->service_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('service_id', (string) $this->service_id);
        }

        if (! Branch::whereKey($this->branch_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('branch_id', (string) $this->branch_id);
        }

        if ($this->patient_id !== null && ! Patient::whereKey($this->patient_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $this->patient_id);
        }
    }
}
