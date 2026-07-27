<?php

namespace Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Pharmacy\Exceptions\MedicationAdministrationException;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * An eMAR ADMINISTRATION event (PHARMACY.G3) — one APPEND-ONLY row recording that a nurse administered /
 * held / refused a dose of a G2 {@see MedicationOrder}. A correction is a NEW row (model guards + DB
 * triggers, the `medication_order_events` recipe). `stay_id` is a SOFT nullable inpatient ref (no relation).
 * Patient-scoped read-logged ({@see LogsReads}).
 *
 * ELECTRIC FENCE (record-not-judge): `outcome` is the FACT the nurse records; the system computes no safety
 * verdict, no "late/missed" grade, no flag — `scheduled_at` vs `administered_at` is a raw time comparison
 * the UI renders. Medication-safety judgment lives ONLY behind the certified-partner seam.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $medication_order_id
 * @property int $administered_by
 * @property string $outcome
 * @property Carbon $administered_at
 * @property Carbon|null $scheduled_at
 * @property string|null $dose_amount
 * @property string|null $dose_unit
 * @property string|null $reason
 * @property string|null $stay_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MedicationOrder|null $order
 */
class MedicationAdministration extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    public const OUTCOME_GIVEN = 'given';

    public const OUTCOME_HELD = 'held';

    public const OUTCOME_REFUSED = 'refused';

    public const OUTCOMES = [self::OUTCOME_GIVEN, self::OUTCOME_HELD, self::OUTCOME_REFUSED];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'medication_order_id',
        'administered_by',
        'outcome',
        'administered_at',
        'scheduled_at',
        'dose_amount',
        'dose_unit',
        'reason',
        'stay_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (MedicationAdministration $administration): void {
            if (! in_array($administration->outcome, self::OUTCOMES, true)) {
                throw MedicationAdministrationException::invalidOutcome((string) $administration->outcome);
            }
        });

        // Append-only: an administration is an immutable clinical event (belt for the DB triggers).
        static::updating(fn () => throw MedicationAdministrationException::appendOnly());
        static::deleting(fn () => throw MedicationAdministrationException::appendOnly());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'administered_at' => 'datetime',
            'scheduled_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MedicationOrder::class, 'medication_order_id');
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
