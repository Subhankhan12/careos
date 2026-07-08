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
 * @property string|null $rescheduled_from_id
 * @property string|null $patient_id
 * @property string $service_id
 * @property string $branch_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $status
 * @property string|null $status_reason
 * @property string|null $booked_by
 * @property string|null $status_changed_by
 * @property Carbon|null $status_changed_at
 * @property string $source
 * @property string|null $notes
 */
class Appointment extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_BOOKED = 'booked';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_ARRIVED = 'arrived';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NO_SHOW = 'no_show';

    public const STATUS_RESCHEDULED = 'rescheduled';

    public const SOURCE_STAFF = 'staff';

    public const SOURCE_ONLINE = 'online';

    public const SOURCE_AGENT = 'agent';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'rescheduled_from_id',
        'patient_id',
        'service_id',
        'branch_id',
        'starts_at',
        'ends_at',
        'status',
        'status_reason',
        'booked_by',
        'status_changed_by',
        'status_changed_at',
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
            if ($appointment->isDirty(['rescheduled_from_id', 'patient_id', 'service_id', 'branch_id'])) {
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
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public static function blockingStatuses(): array
    {
        return [
            self::STATUS_BOOKED,
            self::STATUS_CONFIRMED,
            self::STATUS_ARRIVED,
            self::STATUS_IN_PROGRESS,
        ];
    }

    /**
     * @return list<string>
     */
    public static function terminalStatuses(): array
    {
        return [
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
            self::STATUS_NO_SHOW,
            self::STATUS_RESCHEDULED,
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

    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_from_id');
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

        if ($this->rescheduled_from_id !== null && ! self::whereKey($this->rescheduled_from_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute(
                'rescheduled_from_id',
                (string) $this->rescheduled_from_id,
            );
        }

        if ($this->patient_id !== null && ! Patient::whereKey($this->patient_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $this->patient_id);
        }
    }
}
