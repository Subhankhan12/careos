<?php

namespace Modules\Hospital\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Hospital\Exceptions\AdmissionException;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A discharge summary (HOSPITAL.G7) — the stay-scoped, clinician-authored SIGN-AND-LOCK document that
 * closes out the inpatient episode. A DISCOVERY like the G5 handover: it is NOT a `ClinicalNote` (SOAP +
 * encounter-scoped) nor an uploaded `Document` (file-shaped), so it OWNS its table while REUSING the
 * sign-and-lock DISCIPLINE (draft -> finalized-immutable, the `ClinicalNote` model guard + the
 * `clinical_notes_signed_*` conditional DB trigger), `BelongsToTenant`, and patient-scoped read-logging.
 *
 * ELECTRIC FENCE (record-not-judge): `summary` + `instructions` are the CLINICIAN'S OWN words; there is
 * deliberately no acuity/severity/score/risk/rating/outlier/readmission field, and length-of-stay is a
 * DERIVED fact on {@see Stay::lengthOfStayMinutes()}, never stored or graded here.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $stay_id
 * @property int $authored_by
 * @property string $summary
 * @property string|null $instructions
 * @property string $status
 * @property Carbon|null $finalized_at
 * @property int|null $finalized_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Stay|null $stay
 */
class DischargeSummary extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_FINALIZED = 'finalized';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'stay_id',
        'authored_by',
        'summary',
        'instructions',
        'status',
        'finalized_at',
        'finalized_by',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected static function booted(): void
    {
        // Sign-and-lock (belt for the DB trigger): a finalized summary is immutable — a draft stays editable.
        static::updating(function (DischargeSummary $summary): void {
            if ($summary->getOriginal('status') === self::STATUS_FINALIZED) {
                throw AdmissionException::dischargeSummaryFinalized();
            }
        });

        static::deleting(function (DischargeSummary $summary): void {
            if ($summary->status === self::STATUS_FINALIZED) {
                throw AdmissionException::dischargeSummaryFinalized();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'finalized_at' => 'datetime',
        ];
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }

    protected function auditResourceType(): string
    {
        return 'discharge_summary';
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
