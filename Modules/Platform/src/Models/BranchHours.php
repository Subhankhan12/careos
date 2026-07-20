<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * A single weekday's opening hours for a branch. Tenant-owned; one row per
 * (branch, weekday). Either `is_closed` (that day the branch takes no bookings) or a
 * validated [open_time, close_time] window. The booking/slot engine reads these to
 * bound bookable slots — a branch with NO rows is unconfigured and keeps the engine's
 * default window (backward compatible).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $branch_id
 * @property int $weekday
 * @property bool $is_closed
 * @property string|null $open_time
 * @property string|null $close_time
 */
class BranchHours extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'branch_hours';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'branch_id',
        'weekday',
        'is_closed',
        'open_time',
        'close_time',
    ];

    protected $attributes = [
        'is_closed' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'is_closed' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (BranchHours $hours) => $hours->assertValid());
        static::updating(function (BranchHours $hours): void {
            if ($hours->isDirty(['branch_id', 'weekday', 'is_closed', 'open_time', 'close_time'])) {
                $hours->assertValid();
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Minutes-from-midnight of the open time, or null when closed / unset. */
    public function openMinutes(): ?int
    {
        return $this->is_closed || $this->open_time === null ? null : self::toMinutes($this->open_time);
    }

    /** Minutes-from-midnight of the close time, or null when closed / unset. */
    public function closeMinutes(): ?int
    {
        return $this->is_closed || $this->close_time === null ? null : self::toMinutes($this->close_time);
    }

    public static function toMinutes(string $time): int
    {
        [$h, $m] = array_pad(explode(':', $time), 2, '0');

        return ((int) $h) * 60 + (int) $m;
    }

    private function assertValid(): void
    {
        // The global scope makes a foreign-tenant branch_id resolve to nothing.
        if (! Branch::whereKey($this->branch_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('branch_id', (string) $this->branch_id);
        }

        if ($this->weekday < 0 || $this->weekday > 6) {
            throw new InvalidArgumentException('weekday must be 0 (Sunday) through 6 (Saturday).');
        }

        if ($this->is_closed) {
            return;
        }

        if ($this->open_time === null || $this->close_time === null) {
            throw new InvalidArgumentException('An open day requires both open_time and close_time.');
        }

        if (self::toMinutes($this->close_time) <= self::toMinutes($this->open_time)) {
            throw new InvalidArgumentException('close_time must be after open_time.');
        }
    }
}
