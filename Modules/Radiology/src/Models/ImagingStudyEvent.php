<?php

namespace Modules\Radiology\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Radiology\Exceptions\ImagingStudyException;

/**
 * The APPEND-ONLY imaging-study state history (RAD.G3) — one immutable row for the initial `ordered` + one per
 * state transition (acquired / reported / cancelled) + an optional reason + who + when (the `specimen_events` /
 * `ed_visit_events` recipe). A correction is a NEW row, never an edit: model `updating`/`deleting` guards (belt)
 * + `SIGNAL '45000'` DB triggers (suspenders). Patient-scoped. No image/pixel data — the image is the PACS
 * partner's (RAD.G6).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $imaging_study_id
 * @property string $event_type
 * @property string|null $reason
 * @property int $performed_by
 * @property Carbon $occurred_at
 * @property-read ImagingStudy|null $study
 */
class ImagingStudyEvent extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    public const TYPE_ORDERED = 'ordered';

    public const TYPE_ACQUIRED = 'acquired';

    public const TYPE_REPORTED = 'reported';

    public const TYPE_CANCELLED = 'cancelled';

    public const EVENT_TYPES = [self::TYPE_ORDERED, self::TYPE_ACQUIRED, self::TYPE_REPORTED, self::TYPE_CANCELLED];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'imaging_study_id',
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
        static::creating(function (ImagingStudyEvent $event): void {
            if (! in_array($event->event_type, self::EVENT_TYPES, true)) {
                throw ImagingStudyException::invalidEventType((string) $event->event_type);
            }
        });

        // Append-only: an immutable record of fact (belt for the DB triggers).
        static::updating(fn () => throw ImagingStudyException::appendOnly());
        static::deleting(fn () => throw ImagingStudyException::appendOnly());
    }

    public function study(): BelongsTo
    {
        return $this->belongsTo(ImagingStudy::class, 'imaging_study_id');
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
