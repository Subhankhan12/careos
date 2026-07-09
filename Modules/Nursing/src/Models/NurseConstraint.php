<?php

namespace Modules\Nursing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Scheduling\Models\Resource;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $resource_id
 * @property string $qualification
 * @property string $max_hours_per_week
 * @property int $max_travel_minutes_between_visits
 */
class NurseConstraint extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'resource_id',
        'qualification',
        'max_hours_per_week',
        'max_travel_minutes_between_visits',
    ];

    protected static function booted(): void
    {
        static::creating(fn (NurseConstraint $constraint) => $constraint->assertValidResource());
        static::updating(function (NurseConstraint $constraint): void {
            if ($constraint->isDirty(['resource_id', 'qualification', 'max_hours_per_week', 'max_travel_minutes_between_visits'])) {
                $constraint->assertValidResource();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_hours_per_week' => 'decimal:2',
            'max_travel_minutes_between_visits' => 'integer',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    private function assertValidResource(): void
    {
        $resource = Resource::query()->whereKey($this->resource_id)->first();

        if ($resource === null) {
            throw CrossTenantReferenceException::forAttribute('resource_id', (string) $this->resource_id);
        }

        if ($resource->type !== Resource::TYPE_PRACTITIONER) {
            throw new InvalidArgumentException('Nurse constraints may only be attached to practitioner resources.');
        }

        if (trim($this->qualification) === '') {
            throw new InvalidArgumentException('Nurse qualification is required.');
        }

        if ((float) $this->max_hours_per_week <= 0) {
            throw new InvalidArgumentException('Nurse max hours per week must be greater than zero.');
        }
    }
}
