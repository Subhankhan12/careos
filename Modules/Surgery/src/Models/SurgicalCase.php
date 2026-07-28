<?php

namespace Modules\Surgery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Surgery\Exceptions\SurgicalCaseException;

/**
 * A surgical CASE (SURGERY.G1) — a NET-NEW entity (docs/HOSPITAL-PHASE5-SURGERY-MAP.md §2.2; neither a
 * single-sitting Encounter nor a fixed clinic slot). G1 ships the case + the `scheduled` status; the full
 * lifecycle (scheduled → pre_op → in_progress → completed → post_op) + append-only case events are
 * SURGERY.G2. The mutable CURRENT state; patient + surgeon scoped; patient read-logged ({@see LogsReads}).
 *
 * `stay_id` is a SOFT nullable ref to a Phase-1 inpatient stay (no FK/relation — Surgery stays arch-
 * independent of Hospital; null = day-surgery / outpatient), composed app-layer (the pharmacy precedent).
 *
 * ELECTRIC FENCE: an operational/scheduling record — every field is a human-recorded fact. There is
 * deliberately NO computed acuity / priority / risk / severity / triage / score column: a surgical-risk score
 * is the fence line (map §3), a certified-partner / non-goal, NEVER computed here.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $primary_surgeon_id
 * @property string|null $stay_id
 * @property string $procedure_description
 * @property Carbon $scheduled_at
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Patient|null $patient
 * @property-read StaffProfile|null $primarySurgeon
 * @property-read TheatreSlot|null $slot
 */
class SurgicalCase extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    public const STATUS_SCHEDULED = 'scheduled';

    // The intended peri-operative lifecycle (docs map §2.2). G1 creates a case at `scheduled`; the legal-only
    // transition machine (pre_op → in_progress → completed → post_op, cancelled) is SURGERY.G2.
    public const STATUS_PRE_OP = 'pre_op';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_POST_OP = 'post_op';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_SCHEDULED, self::STATUS_PRE_OP, self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED, self::STATUS_POST_OP, self::STATUS_CANCELLED,
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'primary_surgeon_id',
        'stay_id',
        'procedure_description',
        'scheduled_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_SCHEDULED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (SurgicalCase $case): void {
            if (trim((string) $case->procedure_description) === '') {
                throw SurgicalCaseException::procedureRequired();
            }
        });

        static::creating(function (SurgicalCase $case): void {
            $case->assertReferencesWithinTenant();
        });

        static::updating(function (SurgicalCase $case): void {
            if ($case->isDirty(['patient_id', 'primary_surgeon_id'])) {
                $case->assertReferencesWithinTenant();
            }
        });
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function primarySurgeon(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'primary_surgeon_id');
    }

    /** The booked theatre block for this case (soft link — a slot points here via `surgical_case_id`). */
    public function slot(): HasOne
    {
        return $this->hasOne(TheatreSlot::class, 'surgical_case_id');
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }

    /**
     * patient + surgeon are tenant-scoped by BelongsToTenant, so one owned by another tenant is invisible and
     * rejected as a cross-tenant link. `stay_id` is a deliberate SOFT ref (a Hospital id Surgery cannot see
     * through its own scope) and is not existence-checked here.
     */
    private function assertReferencesWithinTenant(): void
    {
        $checks = [
            'patient_id' => fn (): bool => Patient::whereKey($this->patient_id)->exists(),
            'primary_surgeon_id' => fn (): bool => StaffProfile::whereKey($this->primary_surgeon_id)->exists(),
        ];

        foreach ($checks as $attribute => $exists) {
            if (! empty($this->{$attribute}) && ! $exists()) {
                throw CrossTenantReferenceException::forAttribute($attribute, (string) $this->{$attribute});
            }
        }
    }
}
