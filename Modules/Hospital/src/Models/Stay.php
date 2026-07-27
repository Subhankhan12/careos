<?php

namespace Modules\Hospital\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Hospital\Exceptions\AdmissionException;
use Modules\Patients\Models\Patient;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;

/**
 * An inpatient STAY / admission (HOSPITAL.G2) — a patient's multi-day inpatient episode,
 * a NET-NEW entity ABOVE the outpatient Encounter (docs/HOSPITAL-PHASE1-ADT-MAP.md §2.2;
 * the VisitPlan->Visit analogue). Encounter is left UNMODIFIED — bedside charting reuses it
 * per ward-round in HOSPITAL.G4. This is the mutable CURRENT state (status, current bed/ward,
 * discharge); the immutable admit/transfer/discharge history is {@see StayEvent} (append-only).
 *
 * The ADT lifecycle is a legal-only state machine (admitted -> discharged; a transfer is a
 * bed-move WITHIN admitted, not a status change). Tenant + branch scoped; patient-scoped
 * read-logged ({@see LogsReads}). ELECTRIC FENCE: `admission_type` and `discharge_disposition`
 * are human-recorded operational facts (route / outcome), never a computed acuity or triage.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $branch_id
 * @property string $admitting_clinician_id
 * @property string $current_bed_id
 * @property string $current_ward_id
 * @property string $status
 * @property string $admission_type
 * @property string|null $admission_reason
 * @property Carbon $admitted_at
 * @property Carbon|null $discharged_at
 * @property string|null $discharge_disposition
 * @property-read Patient|null $patient
 * @property-read StaffProfile|null $admittingClinician
 * @property-read Bed|null $currentBed
 * @property-read Ward|null $currentWard
 */
class Stay extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    public const STATUS_ADMITTED = 'admitted';

    public const STATUS_DISCHARGED = 'discharged';

    public const STATUSES = [self::STATUS_ADMITTED, self::STATUS_DISCHARGED];

    // The legal lifecycle. A transfer is a bed-move that KEEPS status = admitted, so it is not
    // a transition here; only admit (-> admitted) and discharge (admitted -> discharged) are.
    public const TRANSITIONS = [
        self::STATUS_ADMITTED => [self::STATUS_DISCHARGED],
        self::STATUS_DISCHARGED => [],
    ];

    // Admission ROUTE — an operational classification a human records, not a clinical acuity.
    public const TYPE_ELECTIVE = 'elective';

    public const TYPE_EMERGENCY = 'emergency';

    public const TYPE_TRANSFER = 'transfer';

    public const ADMISSION_TYPES = [self::TYPE_ELECTIVE, self::TYPE_EMERGENCY, self::TYPE_TRANSFER];

    // Discharge outcome — an operational fact recorded at discharge.
    public const DISPOSITION_HOME = 'home';

    public const DISPOSITION_TRANSFERRED_OUT = 'transferred_out';

    public const DISPOSITION_DECEASED = 'deceased';

    public const DISPOSITION_AGAINST_ADVICE = 'against_advice';

    public const DISPOSITIONS = [
        self::DISPOSITION_HOME, self::DISPOSITION_TRANSFERRED_OUT,
        self::DISPOSITION_DECEASED, self::DISPOSITION_AGAINST_ADVICE,
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'branch_id',
        'admitting_clinician_id',
        'current_bed_id',
        'current_ward_id',
        'admitted_at',
        'admission_type',
        'admission_reason',
    ];

    protected $attributes = [
        'status' => self::STATUS_ADMITTED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'admitted_at' => 'datetime',
            'discharged_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Stay $stay): void {
            $stay->assertValidAdmissionType();
            $stay->assertReferencesWithinTenant();
        });

        static::updating(function (Stay $stay): void {
            if ($stay->isDirty(['patient_id', 'branch_id', 'admitting_clinician_id', 'current_bed_id', 'current_ward_id'])) {
                $stay->assertReferencesWithinTenant();
            }
        });
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Length-of-stay in whole minutes — DERIVED elapsed time (discharged_at − admitted_at), null while
     * still admitted. A raw FACT computed on read: never stored, never a judgment. ELECTRIC FENCE: there
     * is deliberately no "too long"/outlier grade, no expected-vs-actual, no LOS rating — the map (§3) flags
     * an LOS-outlier flag as a clinician-/ops-set threshold, NOT a system grade. The UI renders days/hours.
     */
    public function lengthOfStayMinutes(): ?int
    {
        if ($this->discharged_at === null) {
            return null;
        }

        return (int) abs($this->admitted_at->diffInMinutes($this->discharged_at));
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function admittingClinician(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'admitting_clinician_id');
    }

    public function currentBed(): BelongsTo
    {
        return $this->belongsTo(Bed::class, 'current_bed_id');
    }

    public function currentWard(): BelongsTo
    {
        return $this->belongsTo(Ward::class, 'current_ward_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(StayEvent::class);
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }

    private function assertValidAdmissionType(): void
    {
        if (! in_array($this->admission_type, self::ADMISSION_TYPES, true)) {
            throw AdmissionException::invalidAdmissionType((string) $this->admission_type);
        }
    }

    /**
     * Every reference is tenant-scoped by BelongsToTenant, so a patient/branch/clinician/
     * bed/ward owned by another tenant is invisible here and rejected as a cross-tenant link.
     */
    private function assertReferencesWithinTenant(): void
    {
        $checks = [
            'patient_id' => fn (): bool => Patient::whereKey($this->patient_id)->exists(),
            'branch_id' => fn (): bool => Branch::whereKey($this->branch_id)->exists(),
            'admitting_clinician_id' => fn (): bool => StaffProfile::whereKey($this->admitting_clinician_id)->exists(),
            'current_bed_id' => fn (): bool => Bed::whereKey($this->current_bed_id)->exists(),
            'current_ward_id' => fn (): bool => Ward::whereKey($this->current_ward_id)->exists(),
        ];

        foreach ($checks as $attribute => $exists) {
            if (! empty($this->{$attribute}) && ! $exists()) {
                throw CrossTenantReferenceException::forAttribute($attribute, (string) $this->{$attribute});
            }
        }
    }
}
