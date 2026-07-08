<?php

namespace Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Models\Branch;

/**
 * A tenant-owned bookable resource consumed by appointments.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $type
 * @property string $name
 * @property string|null $staff_profile_id
 * @property string $branch_id
 * @property bool $active
 */
class Resource extends Model
{
    use BelongsToTenant, HasUlids;

    public const TYPE_PRACTITIONER = 'practitioner';

    public const TYPE_ROOM = 'room';

    public const TYPE_CHAIR = 'chair';

    public const TYPE_VEHICLE = 'vehicle';

    public const TYPES = [
        self::TYPE_PRACTITIONER,
        self::TYPE_ROOM,
        self::TYPE_CHAIR,
        self::TYPE_VEHICLE,
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'type',
        'name',
        'staff_profile_id',
        'branch_id',
        'active',
    ];

    protected $attributes = [
        'active' => true,
    ];

    protected static function booted(): void
    {
        static::creating(function (Resource $resource): void {
            $resource->assertValidResource();
        });

        static::updating(function (Resource $resource): void {
            if ($resource->isDirty(['type', 'staff_profile_id', 'branch_id'])) {
                $resource->assertValidResource();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function availability(): HasMany
    {
        return $this->hasMany(ResourceAvailability::class);
    }

    private function assertValidResource(): void
    {
        if (! in_array($this->type, self::TYPES, true)) {
            throw new InvalidArgumentException('Resource type is not supported.');
        }

        if (! Branch::whereKey($this->branch_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('branch_id', (string) $this->branch_id);
        }

        if ($this->type !== self::TYPE_PRACTITIONER && $this->staff_profile_id !== null) {
            throw new InvalidArgumentException('Only practitioner resources may link to a staff profile.');
        }

        if ($this->staff_profile_id !== null && ! StaffProfile::whereKey($this->staff_profile_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('staff_profile_id', (string) $this->staff_profile_id);
        }
    }
}
