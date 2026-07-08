<?php

namespace Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Models\Branch;

/**
 * A tenant-owned bookable service offered by a clinic/practice.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $code
 * @property string|null $category
 * @property int $default_duration_minutes
 * @property int $buffer_before_minutes
 * @property int $buffer_after_minutes
 * @property list<string> $requires_resource_types
 * @property bool $bookable_online
 * @property bool $active
 */
class Service extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'code',
        'category',
        'default_duration_minutes',
        'buffer_before_minutes',
        'buffer_after_minutes',
        'requires_resource_types',
        'bookable_online',
        'active',
    ];

    protected $attributes = [
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'bookable_online' => false,
        'active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_duration_minutes' => 'integer',
            'buffer_before_minutes' => 'integer',
            'buffer_after_minutes' => 'integer',
            'requires_resource_types' => 'array',
            'bookable_online' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function branchLinks(): HasMany
    {
        return $this->hasMany(ServiceBranch::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'service_branch')
            ->withPivot('tenant_id')
            ->withTimestamps();
    }

    /**
     * A service with no branch links is available at every branch in the tenant.
     */
    public function isAvailableAtBranch(string $branchId): bool
    {
        if (! $this->branchLinks()->exists()) {
            return Branch::whereKey($branchId)->exists();
        }

        return $this->branchLinks()->where('branch_id', $branchId)->exists();
    }
}
