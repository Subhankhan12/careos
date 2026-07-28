<?php

namespace Modules\Surgery\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Surgery\Exceptions\SurgicalCaseException;

/**
 * A surgical CASE (SURGERY.G1 + the G2 lifecycle) — a NET-NEW entity (docs/HOSPITAL-PHASE5-SURGERY-MAP.md
 * §2.2; neither a single-sitting Encounter nor a fixed clinic slot). The mutable CURRENT state; the immutable
 * transition history is {@see SurgicalCaseEvent} (append-only). G2 adds the LEGAL-ONLY lifecycle
 * (scheduled → pre_op → in_progress → completed → post_op, + cancelled), the factual per-phase timestamps,
 * the surgical team, and the anesthetist-ASSIGNED ASA/Mallampati. Patient + surgeon scoped; patient
 * read-logged ({@see LogsReads}). Op documentation reuses Clinical's sign-and-lock `ClinicalNote`/`Encounter`
 * via {@see SurgicalCaseEncounter} (Encounter UNMODIFIED).
 *
 * `stay_id` is a SOFT nullable ref to a Phase-1 inpatient stay (no FK/relation — Surgery stays arch-
 * independent of Hospital; null = day-surgery / outpatient), composed app-layer (the pharmacy precedent).
 *
 * ELECTRIC FENCE: an operational record — every field is a human-recorded fact. There is deliberately NO
 * computed acuity / priority / risk / severity / triage / score column; the ASA/Mallampati classes are
 * ASSIGNED by the anesthetist (recorded facts). A computed surgical-risk score/prediction is the fence line
 * (map §3), a certified-partner / non-goal, NEVER computed here.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $primary_surgeon_id
 * @property string|null $stay_id
 * @property string $procedure_description
 * @property Carbon $scheduled_at
 * @property string $status
 * @property string|null $status_reason
 * @property Carbon|null $pre_op_at
 * @property Carbon|null $in_progress_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $post_op_at
 * @property Carbon|null $cancelled_at
 * @property string|null $asa_class
 * @property string|null $mallampati
 * @property string|null $asa_assessed_by
 * @property Carbon|null $asa_assessed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Patient|null $patient
 * @property-read StaffProfile|null $primarySurgeon
 * @property-read TheatreSlot|null $slot
 * @property-read Collection<int, SurgicalCaseEvent> $events
 * @property-read Collection<int, SurgicalCaseTeamMember> $teamMembers
 * @property-read Collection<int, SurgicalCaseEncounter> $caseEncounters
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

    // The LEGAL-ONLY lifecycle (SURGERY.G2). completed → post_op is terminal; cancelled only from a
    // not-yet-started case (scheduled / pre_op). An illegal move throws SurgicalCaseException::invalidTransition.
    public const TRANSITIONS = [
        self::STATUS_SCHEDULED => [self::STATUS_PRE_OP, self::STATUS_CANCELLED],
        self::STATUS_PRE_OP => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS => [self::STATUS_COMPLETED],
        self::STATUS_COMPLETED => [self::STATUS_POST_OP],
        self::STATUS_POST_OP => [],
        self::STATUS_CANCELLED => [],
    ];

    // ASA physical-status class + Mallampati — closed sets the ANESTHETIST assigns (recorded facts, never
    // computed). CareOS records the assigned value; it does NOT compute it or any surgical-risk score.
    public const ASA_CLASSES = ['I', 'II', 'III', 'IV', 'V', 'VI'];

    public const MALLAMPATI_CLASSES = ['I', 'II', 'III', 'IV'];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'primary_surgeon_id',
        'stay_id',
        'procedure_description',
        'scheduled_at',
        // Lifecycle + anesthetist-assigned values (SURGERY.G2). `status` stays out of fillable — it moves only
        // through the legal-only transition machine (forceFill in the service).
        'status_reason',
        'pre_op_at', 'in_progress_at', 'completed_at', 'post_op_at', 'cancelled_at',
        'asa_class', 'mallampati', 'asa_assessed_by', 'asa_assessed_at',
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
            'pre_op_at' => 'datetime',
            'in_progress_at' => 'datetime',
            'completed_at' => 'datetime',
            'post_op_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'asa_assessed_at' => 'datetime',
        ];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
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

    /** The append-only lifecycle history (one immutable row per transition). */
    public function events(): HasMany
    {
        return $this->hasMany(SurgicalCaseEvent::class);
    }

    /** The surgical team on the case (surgeon + anesthetist + scrub/OR nurse). */
    public function teamMembers(): HasMany
    {
        return $this->hasMany(SurgicalCaseTeamMember::class);
    }

    /** The op-documentation encounters (each holds a sign-and-lock ClinicalNote; Encounter reused unmodified). */
    public function caseEncounters(): HasMany
    {
        return $this->hasMany(SurgicalCaseEncounter::class);
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
