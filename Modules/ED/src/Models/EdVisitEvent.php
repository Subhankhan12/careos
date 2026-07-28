<?php

namespace Modules\ED\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\ED\Exceptions\EdVisitException;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * The APPEND-ONLY ED-visit flow history (ED.G1) — one immutable row for arrival + one per flow transition
 * (triaged / in_treatment / awaiting_disposition / dispositioned / left_without_being_seen) + an optional
 * reason + who + when (the `surgical_case_events` / `stay_events` recipe). A correction is a NEW row, never an
 * edit: model `updating`/`deleting` guards (belt) + `SIGNAL '45000'` DB triggers (suspenders). Patient-scoped.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $ed_visit_id
 * @property string $event_type
 * @property string|null $reason
 * @property int $performed_by
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read EdVisit|null $edVisit
 */
class EdVisitEvent extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    // Arrival + the flow transitions — 1:1 with the status the visit is in after the event.
    public const TYPE_ARRIVED = 'arrived';

    public const TYPE_TRIAGED = 'triaged';

    public const TYPE_IN_TREATMENT = 'in_treatment';

    public const TYPE_AWAITING_DISPOSITION = 'awaiting_disposition';

    public const TYPE_DISPOSITIONED = 'dispositioned';

    public const TYPE_LEFT_WITHOUT_BEING_SEEN = 'left_without_being_seen';

    public const EVENT_TYPES = [
        self::TYPE_ARRIVED, self::TYPE_TRIAGED, self::TYPE_IN_TREATMENT,
        self::TYPE_AWAITING_DISPOSITION, self::TYPE_DISPOSITIONED,
        self::TYPE_LEFT_WITHOUT_BEING_SEEN,
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'ed_visit_id',
        'event_type',
        'reason',
        'performed_by',
        'occurred_at',
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
        static::creating(function (EdVisitEvent $event): void {
            if (! in_array($event->event_type, self::EVENT_TYPES, true)) {
                throw EdVisitException::invalidEventType((string) $event->event_type);
            }
        });

        // Append-only: an immutable record of fact (belt for the DB triggers).
        static::updating(fn () => throw EdVisitException::appendOnly());
        static::deleting(fn () => throw EdVisitException::appendOnly());
    }

    public function edVisit(): BelongsTo
    {
        return $this->belongsTo(EdVisit::class);
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
