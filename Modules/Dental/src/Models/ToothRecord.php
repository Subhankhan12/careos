<?php

namespace Modules\Dental\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Dental\Exceptions\DentalException;
use Modules\Dental\Support\ToothNotation;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * An APPEND-ONLY charting record for one tooth (or one surface of a tooth) at one
 * moment. It stores WHAT THE DENTIST CHARTED — a fact they observed and recorded —
 * and NOTHING interpretive.
 *
 * ELECTRIC FENCE (record-not-judge, same posture as vitals D-D3 and order results
 * D-076): there is NO severity, score, risk, grade, abnormal flag, priority, or
 * recommendation anywhere. `charted_condition` is a value the clinician SELECTED
 * (e.g. `caries` = "I observed decay on this surface"), never a value the system
 * computed. The system does not detect caries, grade decay, assess risk, or
 * diagnose — a clinician does, and this only records it.
 *
 * HISTORY: append-only at model AND DB-trigger level (UPDATE/DELETE throw). A tooth's
 * state over time is the ordered sequence of these records; the patient's CURRENT
 * odontogram is the latest record per (tooth, surface). A correction is a NEW record
 * carrying a `reason` — prior states are never destroyed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $tooth FDI two-digit id (e.g. "11", "55")
 * @property string|null $surface one of ToothNotation::SURFACES, or null for a whole-tooth record
 * @property string $charted_condition a charted fact (whole-tooth status or surface condition)
 * @property string|null $note free-text the clinician added
 * @property string|null $reason why this record supersedes a prior one (a correction)
 * @property int $charted_by
 * @property Carbon $charted_at
 */
class ToothRecord extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    /** Whole-tooth states (surface = null) — factual presence/status the dentist charts. */
    public const WHOLE_TOOTH_CONDITIONS = [
        'present', 'missing', 'unerupted', 'implant', 'pontic', 'crown', 'root_canal', 'bridge_retainer',
    ];

    /** Per-surface conditions (surface set) — charted findings, not computed grades. */
    public const SURFACE_CONDITIONS = [
        'sound', 'caries', 'restoration', 'fracture', 'sealant', 'veneer', 'erosion', 'abrasion',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'tooth',
        'surface',
        'charted_condition',
        'note',
        'reason',
        'charted_by',
        'charted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['charted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (ToothRecord $record): void {
            $record->assertValid();
        });

        // Append-only: corrections are new records + a reason, never edits (mirrors
        // order_results / clinical notes / the financial ledgers). DB triggers enforce
        // this too; this is the model-level guard (defence in depth).
        static::updating(function (): void {
            throw new DentalException('tooth_records are append-only: a correction is a new record, not an edit.');
        });
        static::deleting(function (): void {
            throw new DentalException('tooth_records are append-only: they cannot be deleted.');
        });
    }

    /**
     * Deterministic charting validation — NO interpretation. A tooth must be a valid
     * FDI id; a surface (if given) must be a real anatomical surface; the charted
     * condition must belong to the allowed vocabulary for its scope (whole-tooth vs
     * per-surface). Nothing here grades or judges.
     */
    private function assertValid(): void
    {
        if (! ToothNotation::isValid((string) $this->tooth)) {
            throw new DentalException("Invalid FDI tooth id [{$this->tooth}].");
        }

        if ($this->surface !== null && ! ToothNotation::isSurface((string) $this->surface)) {
            throw new DentalException("Invalid tooth surface [{$this->surface}].");
        }

        $allowed = $this->surface === null ? self::WHOLE_TOOTH_CONDITIONS : self::SURFACE_CONDITIONS;
        if (! in_array((string) $this->charted_condition, $allowed, true)) {
            $scope = $this->surface === null ? 'whole-tooth' : 'surface';
            throw new DentalException("Invalid {$scope} charted condition [{$this->charted_condition}].");
        }
    }

    protected function auditResourceType(): string
    {
        return 'tooth_records';
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
