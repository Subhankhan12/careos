<?php

namespace Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A tenant-owned, time-boxed offer of a freed slot to one waitlist patient.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $waitlist_entry_id
 * @property string|null $source_appointment_id
 * @property string $patient_id
 * @property string $service_id
 * @property string $branch_id
 * @property Carbon $slot_starts_at
 * @property Carbon $slot_ends_at
 * @property list<string> $resource_ids
 * @property string $status
 * @property string|null $offered_by
 * @property Carbon $offered_at
 * @property Carbon $expires_at
 * @property Carbon|null $responded_at
 * @property string|null $booked_appointment_id
 */
class WaitlistOffer extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_OFFERED = 'offered';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_EXPIRED = 'expired';

    /** Slot-holding statuses that block re-offering the same entry. */
    public const OPEN_STATUSES = [self::STATUS_OFFERED];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'waitlist_entry_id',
        'source_appointment_id',
        'patient_id',
        'service_id',
        'branch_id',
        'slot_starts_at',
        'slot_ends_at',
        'resource_ids',
        'status',
        'offered_by',
        'offered_at',
        'expires_at',
        'responded_at',
        'booked_appointment_id',
    ];

    protected $attributes = [
        'status' => self::STATUS_OFFERED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resource_ids' => 'array',
            'slot_starts_at' => 'datetime',
            'slot_ends_at' => 'datetime',
            'offered_at' => 'datetime',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(WaitlistEntry::class, 'waitlist_entry_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function isExpired(?Carbon $asOf = null): bool
    {
        return $this->status === self::STATUS_OFFERED
            && $this->expires_at->lessThanOrEqualTo($asOf ?? Carbon::now());
    }
}
