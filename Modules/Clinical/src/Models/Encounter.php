<?php

namespace Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Modules\Audit\Concerns\LogsReads;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;
use Modules\Scheduling\Models\Appointment;

/**
 * Tenant-owned clinical visit container.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $practitioner_id
 * @property string $branch_id
 * @property string|null $appointment_id
 * @property string $type
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property string $status
 * @property string|null $reason_for_visit
 */
class Encounter extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    public const TYPE_CONSULTATION = 'consultation';

    public const TYPE_FOLLOW_UP = 'follow_up';

    public const TYPE_HOME_VISIT = 'home_visit';

    public const TYPE_PROCEDURE = 'procedure';

    public const TYPE_OTHER = 'other';

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'practitioner_id',
        'branch_id',
        'appointment_id',
        'type',
        'started_at',
        'ended_at',
        'status',
        'reason_for_visit',
    ];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
    ];

    protected static function booted(): void
    {
        static::creating(function (Encounter $encounter): void {
            $encounter->assertTenantReferences();
        });

        static::updating(function (Encounter $encounter): void {
            if ($encounter->isDirty(['patient_id', 'practitioner_id', 'branch_id', 'appointment_id'])) {
                $encounter->assertTenantReferences();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_CONSULTATION,
            self::TYPE_FOLLOW_UP,
            self::TYPE_HOME_VISIT,
            self::TYPE_PROCEDURE,
            self::TYPE_OTHER,
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'practitioner_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    protected function auditResourceType(): string
    {
        return 'encounter';
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

        if (! StaffProfile::query()->whereKey($this->practitioner_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('practitioner_id', (string) $this->practitioner_id);
        }

        if (! Branch::query()->whereKey($this->branch_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('branch_id', (string) $this->branch_id);
        }

        if ($this->appointment_id === null) {
            return;
        }

        $appointment = Appointment::query()->whereKey($this->appointment_id)->first();

        if ($appointment === null) {
            throw CrossTenantReferenceException::forAttribute('appointment_id', (string) $this->appointment_id);
        }

        if ($appointment->patient_id !== $this->patient_id || $appointment->branch_id !== $this->branch_id) {
            throw new InvalidArgumentException('Encounter appointment must match the encounter patient and branch.');
        }
    }
}
