<?php

namespace Modules\Lab\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Lab\Exceptions\SpecimenException;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * The APPEND-ONLY specimen state history (LAB.G3) — one immutable row for collection + one per state
 * transition (in_lab / resulted / rejected) + an optional reason + who + when (the `ed_visit_events` /
 * `stay_events` recipe). A correction is a NEW row, never an edit: model `updating`/`deleting` guards (belt) +
 * `SIGNAL '45000'` DB triggers (suspenders). Patient-scoped.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $specimen_id
 * @property string $event_type
 * @property string|null $reason
 * @property int $performed_by
 * @property Carbon $occurred_at
 * @property-read Specimen|null $specimen
 */
class SpecimenEvent extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    public const TYPE_COLLECTED = 'collected';

    public const TYPE_IN_LAB = 'in_lab';

    public const TYPE_RESULTED = 'resulted';

    public const TYPE_REJECTED = 'rejected';

    public const EVENT_TYPES = [self::TYPE_COLLECTED, self::TYPE_IN_LAB, self::TYPE_RESULTED, self::TYPE_REJECTED];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'specimen_id',
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
        return ['occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (SpecimenEvent $event): void {
            if (! in_array($event->event_type, self::EVENT_TYPES, true)) {
                throw SpecimenException::invalidEventType((string) $event->event_type);
            }
        });

        // Append-only: an immutable record of fact (belt for the DB triggers).
        static::updating(fn () => throw SpecimenException::appendOnly());
        static::deleting(fn () => throw SpecimenException::appendOnly());
    }

    public function specimen(): BelongsTo
    {
        return $this->belongsTo(Specimen::class);
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
