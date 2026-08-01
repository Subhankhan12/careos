<?php

namespace Modules\Lab\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Audit\Concerns\LogsReads;
use Modules\Clinical\Models\Order;
use Modules\Lab\Exceptions\LabOrderException;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * The thin lab-order overlay (LAB.G2) on the EXISTING Clinical `Order` — a lab order IS a Clinical `Order`
 * (REUSED via `OrderService::place`, its lifecycle + the `LabConnectivity` seam UNTOUCHED); this overlay adds
 * ONLY the two lab-specific placement facts: `specimen_type` + the lab `priority` (routine/urgent/STAT).
 * **APPEND-ONLY** (a placement record — model guards + `SIGNAL '45000'` DB triggers, the `OrderResult`
 * recipe). Patient-scoped.
 *
 * ELECTRIC FENCE (docs/HOSPITAL-PHASE3-LAB-MAP.md §4): `priority` is a RECORDED flag the clinician SETS (the
 * ED nurse-assigned-acuity / `Stay.admission_type` precedent) — the system computes NO priority, ranks NOTHING
 * by a computed urgency, auto-escalates NOTHING. No urgency-score/computed-priority column exists.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $order_id
 * @property string|null $specimen_type
 * @property string $priority
 * @property-read Order|null $order
 */
class LabOrder extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    // The lab priority — a RECORDED flag the ordering clinician sets, never computed. (Clinical's `Order`
    // carries routine/urgent only; STAT is a lab-specific flag recorded HERE so Clinical stays untouched.)
    public const PRIORITY_ROUTINE = 'routine';

    public const PRIORITY_URGENT = 'urgent';

    public const PRIORITY_STAT = 'stat';

    public const PRIORITIES = [self::PRIORITY_ROUTINE, self::PRIORITY_URGENT, self::PRIORITY_STAT];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'order_id',
        'specimen_type',
        'priority',
    ];

    protected static function booted(): void
    {
        static::creating(function (LabOrder $labOrder): void {
            if (! in_array($labOrder->priority, self::PRIORITIES, true)) {
                throw LabOrderException::invalidPriority((string) $labOrder->priority);
            }
        });

        // Append-only: a lab-order placement is an immutable record of fact (belt for the DB triggers).
        static::updating(fn () => throw LabOrderException::appendOnly());
        static::deleting(fn () => throw LabOrderException::appendOnly());
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
