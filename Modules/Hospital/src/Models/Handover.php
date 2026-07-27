<?php

namespace Modules\Hospital\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Hospital\Exceptions\HandoverException;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A nursing shift handover for an inpatient stay (HOSPITAL.G5) — a NET-NEW structured SBAR
 * artifact the OUTGOING NURSE authors for the oncoming shift. APPEND-ONLY (model guards +
 * DB triggers): a correction is a new handover + reason; the shift-by-shift history is preserved.
 * Patient-scoped read-logged ({@see LogsReads}); tenant-owned.
 *
 * ELECTRIC FENCE (record-not-judge): the SBAR fields are what the nurse WROTE. `assessment` is
 * the nurse's own written assessment (a SBAR section, like a note's SOAP assessment), NOT a
 * computed acuity/score. There is no severity/acuity/score/risk/priority/flag field, and nothing
 * auto-populates any field — the system records the handover, it never computes a judgment.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $stay_id
 * @property int $authored_by
 * @property string $shift
 * @property string $situation
 * @property string|null $background
 * @property string|null $assessment
 * @property string|null $recommendation
 * @property string|null $reason
 * @property Carbon $handed_over_at
 * @property-read Stay|null $stay
 */
class Handover extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    // Operational shift label a nurse selects — a fact, not a judgment.
    public const SHIFT_DAY = 'day';

    public const SHIFT_EVENING = 'evening';

    public const SHIFT_NIGHT = 'night';

    public const SHIFTS = [self::SHIFT_DAY, self::SHIFT_EVENING, self::SHIFT_NIGHT];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'stay_id',
        'authored_by',
        'shift',
        'situation',
        'background',
        'assessment',
        'recommendation',
        'reason',
        'handed_over_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'handed_over_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Handover $handover): void {
            if (! in_array($handover->shift, self::SHIFTS, true)) {
                throw HandoverException::invalidShift((string) $handover->shift);
            }
            if (trim((string) $handover->situation) === '') {
                throw HandoverException::situationRequired();
            }
        });

        // Append-only: a handover is a shift record of fact (belt for the DB triggers).
        static::updating(fn () => throw HandoverException::appendOnly());
        static::deleting(fn () => throw HandoverException::appendOnly());
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
