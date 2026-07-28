<?php

namespace Modules\Surgery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Surgery\Exceptions\SurgicalCaseException;

/**
 * The APPEND-ONLY surgical-case lifecycle history (SURGERY.G2) — one immutable row per transition
 * (pre_op / in_progress / completed / post_op / cancelled) + the clinician's reason + who + when (the
 * `medication_order_events` / `stay_events` recipe). A correction is a NEW row, never an edit: model
 * `updating`/`deleting` guards (belt) + `SIGNAL '45000'` DB triggers (suspenders). Patient-scoped.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $surgical_case_id
 * @property string $event_type
 * @property string|null $reason
 * @property int $performed_by
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SurgicalCase|null $surgicalCase
 */
class SurgicalCaseEvent extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    // The lifecycle events — 1:1 with the target status a transition moves the case into.
    public const TYPE_PRE_OP = 'pre_op';

    public const TYPE_IN_PROGRESS = 'in_progress';

    public const TYPE_COMPLETED = 'completed';

    public const TYPE_POST_OP = 'post_op';

    public const TYPE_CANCELLED = 'cancelled';

    public const EVENT_TYPES = [
        self::TYPE_PRE_OP, self::TYPE_IN_PROGRESS, self::TYPE_COMPLETED,
        self::TYPE_POST_OP, self::TYPE_CANCELLED,
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'surgical_case_id',
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
        static::creating(function (SurgicalCaseEvent $event): void {
            if (! in_array($event->event_type, self::EVENT_TYPES, true)) {
                throw SurgicalCaseException::invalidEventType((string) $event->event_type);
            }
        });

        // Append-only: an immutable record of fact (belt for the DB triggers).
        static::updating(fn () => throw SurgicalCaseException::appendOnly());
        static::deleting(fn () => throw SurgicalCaseException::appendOnly());
    }

    public function surgicalCase(): BelongsTo
    {
        return $this->belongsTo(SurgicalCase::class);
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
