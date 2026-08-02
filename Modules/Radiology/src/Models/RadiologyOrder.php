<?php

namespace Modules\Radiology\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Audit\Concerns\LogsReads;
use Modules\Clinical\Models\Order;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Radiology\Exceptions\RadiologyOrderException;

/**
 * The thin imaging-order overlay (RAD.G2) on the EXISTING Clinical `Order` — an imaging order IS a Clinical
 * `Order` (REUSED via `OrderService::place`, its lifecycle UNTOUCHED); this overlay adds ONLY the three
 * imaging-specific placement facts: `modality` + `body_part` + the imaging `priority` (routine/urgent/STAT).
 * **APPEND-ONLY** (a placement record — model guards + `SIGNAL '45000'` DB triggers, the `lab_orders` recipe).
 * Patient-scoped.
 *
 * ELECTRIC FENCE (docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md §4): `priority` is a RECORDED flag the clinician SETS
 * (the LAB.G2 / ED nurse-assigned-acuity precedent) — the system computes NO priority, ranks NOTHING by a
 * computed urgency, auto-escalates NOTHING. No urgency-score/computed-priority column exists; and NO computed
 * image finding/CAD column (there is no image yet — this is order entry).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $order_id
 * @property string|null $modality
 * @property string|null $body_part
 * @property string $priority
 * @property-read Order|null $order
 */
class RadiologyOrder extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    // The imaging priority — a RECORDED flag the ordering clinician sets, never computed. (Clinical's `Order`
    // carries routine/urgent only; STAT is an imaging-specific flag recorded HERE so Clinical stays untouched.)
    public const PRIORITY_ROUTINE = 'routine';

    public const PRIORITY_URGENT = 'urgent';

    public const PRIORITY_STAT = 'stat';

    public const PRIORITIES = [self::PRIORITY_ROUTINE, self::PRIORITY_URGENT, self::PRIORITY_STAT];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'order_id',
        'modality',
        'body_part',
        'priority',
    ];

    protected static function booted(): void
    {
        static::creating(function (RadiologyOrder $radiologyOrder): void {
            if (! in_array($radiologyOrder->priority, self::PRIORITIES, true)) {
                throw RadiologyOrderException::invalidPriority((string) $radiologyOrder->priority);
            }
        });

        // Append-only: an imaging-order placement is an immutable record of fact (belt for the DB triggers).
        static::updating(fn () => throw RadiologyOrderException::appendOnly());
        static::deleting(fn () => throw RadiologyOrderException::appendOnly());
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
