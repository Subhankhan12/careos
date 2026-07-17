<?php

namespace Modules\Nursing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A tenant-authored nurse competency. NOT a licensed catalog. Each competency's
 * enforcement is the AGENCY's choice: HARD blocks assignment (like a qualification),
 * SOFT warns the dispatcher but allows it. The system never decides which are
 * safety-critical — it enforces the configured rule.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $enforcement
 * @property bool $active
 */
class Competency extends Model
{
    use BelongsToTenant, HasUlids;

    public const ENFORCEMENT_HARD = 'hard';

    public const ENFORCEMENT_SOFT = 'soft';

    public const ENFORCEMENTS = [self::ENFORCEMENT_HARD, self::ENFORCEMENT_SOFT];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'code',
        'name',
        'description',
        'enforcement',
        'active',
    ];

    protected $attributes = [
        'enforcement' => self::ENFORCEMENT_HARD,
        'active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function nurseCompetencies(): HasMany
    {
        return $this->hasMany(NurseCompetency::class);
    }
}
