<?php

namespace Modules\Patients\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * Versioned tenant-owned consent text and scope grants.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $key
 * @property string $title
 * @property string $body
 * @property int $version
 * @property list<string> $scope_keys
 * @property bool $is_active
 */
class ConsentTemplate extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'key',
        'title',
        'body',
        'version',
        'scope_keys',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope_keys' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function consents(): HasMany
    {
        return $this->hasMany(PatientConsent::class, 'template_id');
    }
}
