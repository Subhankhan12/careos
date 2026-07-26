<?php

namespace Modules\Hospital\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Hospital\Exceptions\AdmissionException;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * One immutable ADT event on a stay (HOSPITAL.G2) — admit / transfer / discharge. The
 * append-only history that preserves the patient's full bed journey: a correction is a NEW
 * event, never an edit. Enforced at the model level ({@see booted}) AND by DB triggers
 * (stay_events_no_update / _no_delete) — the tooth_records / order_results posture.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $stay_id
 * @property string $event_type
 * @property string|null $bed_id
 * @property string|null $from_bed_id
 * @property string|null $ward_id
 * @property string|null $reason
 * @property string|null $disposition
 * @property Carbon $occurred_at
 * @property int $performed_by
 */
class StayEvent extends Model
{
    use BelongsToTenant, HasUlids;

    public const TYPE_ADMITTED = 'admitted';

    public const TYPE_TRANSFERRED = 'transferred';

    public const TYPE_DISCHARGED = 'discharged';

    public const TYPES = [self::TYPE_ADMITTED, self::TYPE_TRANSFERRED, self::TYPE_DISCHARGED];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'stay_id',
        'event_type',
        'bed_id',
        'from_bed_id',
        'ward_id',
        'reason',
        'disposition',
        'occurred_at',
        'performed_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StayEvent $event): void {
            if (! in_array($event->event_type, self::TYPES, true)) {
                throw AdmissionException::invalidEventType((string) $event->event_type);
            }
        });

        // Append-only: a stay event is a record of fact (belt for the DB triggers).
        static::updating(fn () => throw AdmissionException::appendOnly());
        static::deleting(fn () => throw AdmissionException::appendOnly());
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }
}
