<?php

namespace Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Pharmacy\Exceptions\MedicationOrderException;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * The APPEND-ONLY history of a medication order (PHARMACY.G2) — one immutable row per lifecycle transition
 * (placed / held / resumed / discontinued / completed), preserving who + when + why. The `stay_events`
 * recipe: model guards (belt) + DB triggers (suspenders). A correction is a NEW event, never an edit.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $medication_order_id
 * @property string $event_type
 * @property string|null $reason
 * @property int $performed_by
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MedicationOrderEvent extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    public const TYPE_PLACED = 'placed';

    public const TYPE_HELD = 'held';

    public const TYPE_RESUMED = 'resumed';

    public const TYPE_DISCONTINUED = 'discontinued';

    public const TYPE_COMPLETED = 'completed';

    public const EVENT_TYPES = [
        self::TYPE_PLACED, self::TYPE_HELD, self::TYPE_RESUMED,
        self::TYPE_DISCONTINUED, self::TYPE_COMPLETED,
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'medication_order_id',
        'event_type',
        'reason',
        'performed_by',
        'occurred_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (MedicationOrderEvent $event): void {
            if (! in_array($event->event_type, self::EVENT_TYPES, true)) {
                throw MedicationOrderException::invalidEventType((string) $event->event_type);
            }
        });

        // Append-only: a medication-order event is an immutable record of fact (belt for the DB triggers).
        static::updating(fn () => throw MedicationOrderException::appendOnly());
        static::deleting(fn () => throw MedicationOrderException::appendOnly());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
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
