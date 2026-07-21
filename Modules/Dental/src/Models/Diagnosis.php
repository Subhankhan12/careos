<?php

namespace Modules\Dental\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Dental\Exceptions\DentalException;
use Modules\Dental\Support\ToothNotation;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A clinical diagnosis the DENTIST entered (DENTAL.G7) — the sharpest fence in the vertical.
 * It stores WHAT THE DENTIST DECIDED: the diagnosis text they wrote or picked from their own
 * list, the tooth it relates to (optional), the supporting findings they reference, and the
 * status THEY set. NOTHING here is proposed, ranked, suggested, or computed by the system.
 *
 * ELECTRIC FENCE (do not compromise): there is NO suggested / proposed / differential /
 * likelihood / confidence / ranked / ai / recommended field anywhere, and no code path
 * auto-populates a diagnosis from the charting / perio / imaging. `status` is the DENTIST'S
 * determination (provisional / confirmed / ruled_out) — the system records the value the dentist
 * set; it never decides or suggests it. `assertValid()` below is pure data-entry validation (a
 * real FDI id, a real surface, a known status the dentist chose, a non-empty label) — it never
 * infers or ranks a diagnosis.
 *
 * APPEND-ONLY at model AND DB-trigger level: a change (status change or correction) is a NEW
 * record carrying a `reason`; prior diagnoses are never destroyed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property int $diagnosed_by
 * @property string|null $diagnosis_term_id provenance: which pick-list term was chosen (null = free text)
 * @property string $label the diagnosis text the dentist entered/chose
 * @property string|null $tooth FDI id it relates to (optional)
 * @property string|null $surface optional FDI surface
 * @property string $status provisional | confirmed | ruled_out — DENTIST-set
 * @property string|null $findings supporting notes/findings the dentist references
 * @property string|null $reason why this supersedes a prior record (a change)
 * @property Carbon $diagnosed_at
 */
class Diagnosis extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    /** The status the DENTIST determines — never computed or suggested by the system. */
    public const STATUS_PROVISIONAL = 'provisional';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_RULED_OUT = 'ruled_out';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_PROVISIONAL, self::STATUS_CONFIRMED, self::STATUS_RULED_OUT];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'diagnosed_by',
        'diagnosis_term_id',
        'label',
        'tooth',
        'surface',
        'status',
        'findings',
        'reason',
        'diagnosed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['diagnosed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<DiagnosisTerm, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(DiagnosisTerm::class, 'diagnosis_term_id');
    }

    protected static function booted(): void
    {
        static::creating(function (Diagnosis $diagnosis): void {
            $diagnosis->assertValid();
        });

        // Append-only: a change is a new record + reason, never an edit (DB triggers enforce this
        // too; this is defence in depth).
        static::updating(function (): void {
            throw new DentalException('diagnoses are append-only: a change is a new record, not an edit.');
        });
        static::deleting(function (): void {
            throw new DentalException('diagnoses are append-only: they cannot be deleted.');
        });
    }

    /**
     * DETERMINISTIC data-entry validation — NOT interpretation and NOT suggestion. A label must be
     * present; a tooth/surface (if given) must be a real FDI id/surface; the status must be one of
     * the three the dentist may set. Nothing here infers, ranks, or proposes a diagnosis.
     */
    private function assertValid(): void
    {
        if (trim((string) $this->label) === '') {
            throw new DentalException('A diagnosis needs a label (a term you pick or free text).');
        }

        if ($this->tooth !== null && ! ToothNotation::isValid((string) $this->tooth)) {
            throw new DentalException("Invalid FDI tooth id [{$this->tooth}].");
        }

        if ($this->surface !== null && ! ToothNotation::isSurface((string) $this->surface)) {
            throw new DentalException("Invalid tooth surface [{$this->surface}].");
        }

        if (! in_array((string) $this->status, self::STATUSES, true)) {
            throw new DentalException("Invalid diagnosis status [{$this->status}].");
        }
    }

    protected function auditResourceType(): string
    {
        return 'diagnoses';
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
