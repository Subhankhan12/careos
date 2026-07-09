<?php

namespace Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property array<string, mixed> $criteria
 * @property int $interval_months
 * @property bool $active
 */
class RecallRule extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'criteria',
        'interval_months',
        'active',
    ];

    protected $attributes = [
        'active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'interval_months' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function recalls(): HasMany
    {
        return $this->hasMany(Recall::class, 'rule_id');
    }
}
