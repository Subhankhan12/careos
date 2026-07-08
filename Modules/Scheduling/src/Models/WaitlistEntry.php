<?php

namespace Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Modules\Patients\Models\Patient;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;

/**
 * Tenant-owned patient waitlist request for a service/branch/window.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $service_id
 * @property string|null $branch_id
 * @property Carbon|null $desired_starts_at
 * @property Carbon|null $desired_ends_at
 * @property bool $flexible
 * @property int $priority
 * @property string $status
 * @property Carbon|null $offered_starts_at
 * @property Carbon|null $offered_ends_at
 * @property string|null $offered_branch_id
 */
class WaitlistEntry extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_WAITING = 'waiting';

    public const STATUS_OFFERED = 'offered';

    public const STATUS_BOOKED = 'booked';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'service_id',
        'branch_id',
        'desired_starts_at',
        'desired_ends_at',
        'flexible',
        'priority',
        'status',
        'offered_starts_at',
        'offered_ends_at',
        'offered_branch_id',
    ];

    protected $attributes = [
        'flexible' => true,
        'priority' => 0,
        'status' => self::STATUS_WAITING,
    ];

    protected static function booted(): void
    {
        static::creating(function (WaitlistEntry $entry): void {
            $entry->assertValidEntry();
        });

        static::updating(function (WaitlistEntry $entry): void {
            if ($entry->isDirty([
                'patient_id',
                'service_id',
                'branch_id',
                'desired_starts_at',
                'desired_ends_at',
                'flexible',
                'offered_branch_id',
                'offered_starts_at',
                'offered_ends_at',
            ])) {
                $entry->assertValidEntry();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'desired_starts_at' => 'datetime',
            'desired_ends_at' => 'datetime',
            'flexible' => 'boolean',
            'priority' => 'integer',
            'offered_starts_at' => 'datetime',
            'offered_ends_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    private function assertValidEntry(): void
    {
        if (! Patient::whereKey($this->patient_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('patient_id', (string) $this->patient_id);
        }

        if (! Service::whereKey($this->service_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('service_id', (string) $this->service_id);
        }

        if ($this->branch_id !== null && ! Branch::whereKey($this->branch_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('branch_id', (string) $this->branch_id);
        }

        if ($this->offered_branch_id !== null && ! Branch::whereKey($this->offered_branch_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('offered_branch_id', (string) $this->offered_branch_id);
        }

        if (! $this->flexible && ($this->desired_starts_at === null || $this->desired_ends_at === null)) {
            throw new InvalidArgumentException('A non-flexible waitlist entry requires a desired window.');
        }

        if ($this->desired_starts_at !== null && $this->desired_ends_at !== null
            && $this->desired_ends_at <= $this->desired_starts_at) {
            throw new InvalidArgumentException('Waitlist desired window end must be after the start.');
        }

        if (($this->offered_starts_at === null) !== ($this->offered_ends_at === null)) {
            throw new InvalidArgumentException('Offered slot start and end are required together.');
        }

        if ($this->offered_starts_at !== null && $this->offered_ends_at <= $this->offered_starts_at) {
            throw new InvalidArgumentException('Offered slot end must be after the start.');
        }
    }
}
