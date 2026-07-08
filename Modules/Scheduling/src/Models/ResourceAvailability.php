<?php

namespace Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * Tenant-owned recurring availability or date-specific availability/block.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $resource_id
 * @property int|null $weekday
 * @property string|null $start_time
 * @property string|null $end_time
 * @property string|null $date
 * @property bool $is_available
 * @property string|null $reason
 */
class ResourceAvailability extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'resource_availability';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'resource_id',
        'weekday',
        'start_time',
        'end_time',
        'date',
        'is_available',
        'reason',
    ];

    protected $attributes = [
        'is_available' => true,
    ];

    protected static function booted(): void
    {
        static::creating(function (ResourceAvailability $availability): void {
            $availability->assertValidAvailability();
        });

        static::updating(function (ResourceAvailability $availability): void {
            if ($availability->isDirty(['resource_id', 'weekday', 'start_time', 'end_time', 'date', 'is_available'])) {
                $availability->assertValidAvailability();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'date' => 'date:Y-m-d',
            'is_available' => 'boolean',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function isDateSpecific(): bool
    {
        return $this->date !== null;
    }

    public function isFullDayBlock(): bool
    {
        return $this->isDateSpecific()
            && ! $this->is_available
            && $this->start_time === null
            && $this->end_time === null;
    }

    private function assertValidAvailability(): void
    {
        if (! Resource::whereKey($this->resource_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('resource_id', (string) $this->resource_id);
        }

        if ($this->isDateSpecific()) {
            $this->assertDateSpecificShape();

            return;
        }

        $this->assertRecurringShape();
    }

    private function assertRecurringShape(): void
    {
        if ($this->weekday === null || $this->weekday < 0 || $this->weekday > 6) {
            throw new InvalidArgumentException('Recurring availability requires weekday 0-6.');
        }

        $this->assertTimedWindow();
    }

    private function assertDateSpecificShape(): void
    {
        if ($this->weekday !== null && ($this->weekday < 0 || $this->weekday > 6)) {
            throw new InvalidArgumentException('Availability weekday must be 0-6 when provided.');
        }

        if (! $this->is_available && $this->start_time === null && $this->end_time === null) {
            return;
        }

        $this->assertTimedWindow();
    }

    private function assertTimedWindow(): void
    {
        if ($this->start_time === null || $this->end_time === null) {
            throw new InvalidArgumentException('Availability start_time and end_time are required together.');
        }

        if ($this->timeToMinutes($this->end_time) <= $this->timeToMinutes($this->start_time)) {
            throw new InvalidArgumentException('Availability end_time must be after start_time.');
        }
    }

    private function timeToMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', substr($time, 0, 5)));

        return ($hours * 60) + $minutes;
    }
}
