<?php

namespace Modules\Surgery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Models\User;
use Modules\Surgery\Exceptions\SurgicalCaseException;

/**
 * An ANESTHESIA ASSESSMENT for a {@see SurgicalCase} (QA-FIX.6b, P6-C2, D-209) — the ASA physical-status
 * class and the Mallampati airway class a clinician ASSIGNED, with provenance. **APPEND-ONLY** (a revision
 * is a NEW row; the previous assessment survives) — model `updating`/`deleting` guards (belt) + `SIGNAL
 * '45000'` DB triggers (suspenders), the `ed_triages` / `surgical_checklist_items` recipe. Patient-scoped
 * read-logged ({@see LogsReads}).
 *
 * TWO PEOPLE, TWO COLUMNS, and they must never stand in for each other (the QA-FIX.2a / D-195 rule):
 *   - {@see $assessed_by} is the CLINICIAN whose judgment this is — a `staff_profiles` id the operator
 *     selects, because the anaesthetist who assessed the patient is not always the person at the keyboard.
 *   - {@see $recorded_by} is the ACTOR who entered it — a `users` id taken from the authenticated user and
 *     NEVER submitted by the client. Before this existed, an assessment could name an uninvolved colleague
 *     with no record at all of who typed it (P6-C2).
 *
 * ELECTRIC FENCE: `asa_class` and `mallampati` are values a clinician **ASSIGNS** using their own judgment
 * — RECORDED FACTS, exactly as `EdTriage::acuity_level` is (whose docblock names this very field as the
 * shape it followed). The system does NOT compute, suggest, rank or score them, and there is deliberately
 * no derived risk column here. A computed surgical-risk score is a certified-partner function and is a
 * non-goal; the closed class lists exist for DATA-ENTRY validation only, never as a grade.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $surgical_case_id
 * @property string $assessed_by
 * @property int $recorded_by
 * @property Carbon $assessed_at
 * @property string $asa_class
 * @property string|null $mallampati
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SurgicalCase|null $surgicalCase
 * @property-read StaffProfile|null $assessedBy
 * @property-read User|null $recordedBy
 */
class SurgicalCaseAnesthesiaAssessment extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'surgical_case_id',
        'assessed_by',
        'recorded_by',
        'assessed_at',
        'asa_class',
        'mallampati',
    ];

    protected static function booted(): void
    {
        static::creating(function (SurgicalCaseAnesthesiaAssessment $assessment): void {
            if (! in_array($assessment->asa_class, SurgicalCase::ASA_CLASSES, true)) {
                throw SurgicalCaseException::invalidAsaClass((string) $assessment->asa_class);
            }
            if ($assessment->mallampati !== null && ! in_array($assessment->mallampati, SurgicalCase::MALLAMPATI_CLASSES, true)) {
                throw SurgicalCaseException::invalidMallampati((string) $assessment->mallampati);
            }
        });

        // Append-only: a revision is a NEW row, so the earlier judgment is never destroyed.
        static::updating(fn () => throw SurgicalCaseException::assessmentAppendOnly());
        static::deleting(fn () => throw SurgicalCaseException::assessmentAppendOnly());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assessed_at' => 'datetime',
            'recorded_by' => 'integer',
        ];
    }

    public function surgicalCase(): BelongsTo
    {
        return $this->belongsTo(SurgicalCase::class, 'surgical_case_id');
    }

    /** The clinician whose judgment this is (selected). */
    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'assessed_by');
    }

    /** The actor who entered it (authenticated, never submitted). */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
