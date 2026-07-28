<?php

namespace Modules\ED\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\ED\Contracts\TriageAcuityProvider;
use Modules\ED\Exceptions\EdVisitException;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A TRIAGE record for an {@see EdVisit} (ED.G2) — the triage nurse's arrival assessment: the presenting
 * complaint + the **ASSIGNED** acuity level + provenance (which nurse, which scale). **APPEND-ONLY** (a
 * re-triage is a NEW row; the history preserved) — model `updating`/`deleting` guards (belt) + `SIGNAL '45000'`
 * DB triggers (suspenders). Raw vitals at triage are the EXISTING Clinical `Vital` (recorded separately, RAW —
 * no bands/flags/scores). Patient-scoped read-logged ({@see LogsReads}).
 *
 * ELECTRIC FENCE (the vertical's crux — docs/HOSPITAL-PHASE6-ED-MAP.md §3): `acuity_level` is a value the NURSE
 * **ASSIGNS** applying a protocol with their own judgment — a RECORDED FACT, exactly like
 * `SurgicalCase::asa_class` (anesthetist-assigned) and `Stay::admission_type`. The system does NOT compute it,
 * does NOT suggest it, does NOT rank. `acuity_scale` (ESI/Manchester/CTAS) + `triaged_by` are provenance.
 * There is deliberately NO suggested/computed/score/severity/priority column — a COMPUTED triage acuity is a
 * regulated medical device that lives ONLY behind the empty {@see TriageAcuityProvider}
 * seam (which returns no suggestion today).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $ed_visit_id
 * @property string $triaged_by
 * @property Carbon $triaged_at
 * @property string $presenting_complaint
 * @property string $acuity_scale
 * @property string $acuity_level
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read EdVisit|null $edVisit
 * @property-read StaffProfile|null $triagedBy
 */
class EdTriage extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    // The acuity SCALES a tenant may triage on. The nurse selects one and assigns a level WITHIN it — a closed
    // set for DATA-ENTRY validation (a valid level for the scale), NOT a computed grade (the ASA_CLASSES
    // validation shape). CareOS bundles no licensed protocol content — the scale is the nurse's chosen frame.
    public const SCALE_ESI = 'ESI';

    public const SCALE_MANCHESTER = 'MANCHESTER';

    public const SCALE_CTAS = 'CTAS';

    public const SCALES = [self::SCALE_ESI, self::SCALE_MANCHESTER, self::SCALE_CTAS];

    /**
     * The valid ASSIGNABLE levels per scale — a closed set for data-entry validation ONLY. The nurse picks the
     * level; the system never derives it. ESI/CTAS are 1–5; Manchester is the standard colour set.
     *
     * @var array<string, list<string>>
     */
    public const LEVELS = [
        self::SCALE_ESI => ['1', '2', '3', '4', '5'],
        self::SCALE_MANCHESTER => ['red', 'orange', 'yellow', 'green', 'blue'],
        self::SCALE_CTAS => ['1', '2', '3', '4', '5'],
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'ed_visit_id',
        'triaged_by',
        'triaged_at',
        'presenting_complaint',
        'acuity_scale',
        'acuity_level',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'triaged_at' => 'datetime',
        ];
    }

    /**
     * Is `$level` a valid ASSIGNABLE value on `$scale`? Pure data-entry validation (does the nurse's chosen
     * value belong to the chosen scale) — never a computed grade or a judgment.
     */
    public static function isValidAssignment(string $scale, string $level): bool
    {
        return in_array($level, self::LEVELS[$scale] ?? [], true);
    }

    protected static function booted(): void
    {
        static::creating(function (EdTriage $triage): void {
            if (! in_array($triage->acuity_scale, self::SCALES, true)) {
                throw EdVisitException::invalidAcuityScale((string) $triage->acuity_scale);
            }
            if (! self::isValidAssignment((string) $triage->acuity_scale, (string) $triage->acuity_level)) {
                throw EdVisitException::invalidAcuityLevel((string) $triage->acuity_level, (string) $triage->acuity_scale);
            }
        });

        // Append-only: a triage is an immutable record of fact (belt for the DB triggers).
        static::updating(fn () => throw EdVisitException::triageAppendOnly());
        static::deleting(fn () => throw EdVisitException::triageAppendOnly());
    }

    public function edVisit(): BelongsTo
    {
        return $this->belongsTo(EdVisit::class);
    }

    public function triagedBy(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'triaged_by');
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
