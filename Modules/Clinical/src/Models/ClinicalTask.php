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
 * @property string|null $patient_id
 * @property string|null $care_plan_id
 * @property string|null $encounter_id
 * @property string $title
 * @property string|null $description
 * @property string $assigned_to
 * @property Carbon $due_at
 * @property string $priority
 * @property string $status
 * @property Carbon|null $completed_at
 */
class ClinicalTask extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_DONE = 'done';

    public const STATUS_CANCELLED = 'cancelled';

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_URGENT = 'urgent';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'care_plan_id',
        'encounter_id',
        'title',
        'description',
        'assigned_to',
        'due_at',
        'priority',
        'status',
        'completed_at',
    ];

    protected $attributes = [
        'priority' => self::PRIORITY_NORMAL,
        'status' => self::STATUS_OPEN,
    ];

    protected static function booted(): void
    {
        static::creating(fn (ClinicalTask $task) => $task->assertTenantReferences());
        static::updating(function (ClinicalTask $task): void {
            if ($task->isDirty(['patient_id', 'care_plan_id', 'encounter_id', 'assigned_to'])) {
                $task->assertTenantReferences();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function carePlan(): BelongsTo
    {
        return $this->belongsTo(CarePlan::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'assigned_to');
    }

    protected function auditResourceType(): string
    {
        return 'clinical_task';
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }

    private function assertTenantReferences(): void
    {
        $patientId = $this->patient_id;

        if ($patientId !== null && ! Patient::query()->whereKey($patientId)->exists()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $patientId);
        }

        if ($this->care_plan_id !== null) {
            $carePlan = CarePlan::query()->whereKey($this->care_plan_id)->first();
            if ($carePlan === null) {
                throw CrossTenantReferenceException::forAttribute('care_plan_id', (string) $this->care_plan_id);
            }

            if ($patientId !== null && $carePlan->patient_id !== $patientId) {
                throw CrossTenantReferenceException::forAttribute('care_plan_id', (string) $this->care_plan_id);
            }
        }

        if ($this->encounter_id !== null) {
            $encounter = Encounter::query()->whereKey($this->encounter_id)->first();
            if ($encounter === null) {
                throw CrossTenantReferenceException::forAttribute('encounter_id', (string) $this->encounter_id);
            }

            if ($patientId !== null && $encounter->patient_id !== $patientId) {
                throw CrossTenantReferenceException::forAttribute('encounter_id', (string) $this->encounter_id);
            }
        }

        if (! StaffProfile::query()->whereKey($this->assigned_to)->exists()) {
            throw CrossTenantReferenceException::forAttribute('assigned_to', (string) $this->assigned_to);
        }
    }
}
