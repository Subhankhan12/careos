<?php

namespace Modules\Surgery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Surgery\Services\TheatreSchedulingService;

/**
 * A THEATRE SLOT (SURGERY.G1) — a booked surgical BLOCK in a theatre. A NET-NEW model that reuses the
 * booking/bed overlap-lock INVARIANT (lock-row → assert-no-overlap → insert) but not the day-board model: a
 * bounded pre-planned block (`starts_at` + `ends_at`), booked concurrency-safely by
 * {@see TheatreSchedulingService::bookSlot} so two surgeries never overlap in one
 * theatre. `BLOCKING_STATUSES` are the states that occupy the theatre (the `Appointment::blockingStatuses`
 * analogue). `surgical_case_id` is a SOFT ref (no FK). NO money / judgment stored.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $theatre_id
 * @property string|null $surgical_case_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Theatre|null $theatre
 */
class TheatreSlot extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_BOOKED = 'booked';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_BOOKED, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED, self::STATUS_CANCELLED,
    ];

    // The states that OCCUPY the theatre — a new block may not overlap one (the Appointment::blockingStatuses
    // analogue). A cancelled/completed slot frees the theatre.
    public const BLOCKING_STATUSES = [self::STATUS_BOOKED, self::STATUS_IN_PROGRESS];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'theatre_id',
        'surgical_case_id',
        'starts_at',
        'ends_at',
        'status',
    ];

    protected $attributes = [
        'status' => self::STATUS_BOOKED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function theatre(): BelongsTo
    {
        return $this->belongsTo(Theatre::class);
    }
}
