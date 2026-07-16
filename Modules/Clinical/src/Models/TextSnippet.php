<?php

namespace Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\People\Models\StaffProfile;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A reusable clinical dot-phrase. NOT patient data.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $scope
 * @property string|null $owner_staff_id
 * @property string $trigger
 * @property string $title
 * @property string $body
 * @property string|null $specialty
 * @property bool $active
 */
class TextSnippet extends Model
{
    use BelongsToTenant, HasUlids;

    public const SCOPE_PERSONAL = 'personal';

    public const SCOPE_SHARED = 'shared';

    public const SCOPES = [self::SCOPE_PERSONAL, self::SCOPE_SHARED];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'scope',
        'owner_staff_id',
        'trigger',
        'title',
        'body',
        'specialty',
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
        return ['active' => 'boolean'];
    }

    /**
     * @return BelongsTo<StaffProfile, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'owner_staff_id');
    }

    public function isPersonal(): bool
    {
        return $this->scope === self::SCOPE_PERSONAL;
    }

    public function isShared(): bool
    {
        return $this->scope === self::SCOPE_SHARED;
    }
}
