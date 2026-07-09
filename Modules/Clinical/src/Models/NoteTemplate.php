<?php

namespace Modules\Clinical\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * Tenant-owned SOAP note template.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $specialty
 * @property string|null $default_subjective
 * @property string|null $default_objective
 * @property string|null $default_assessment
 * @property string|null $default_plan
 * @property array<int, string> $required_sections
 * @property bool $active
 */
class NoteTemplate extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'specialty',
        'default_subjective',
        'default_objective',
        'default_assessment',
        'default_plan',
        'required_sections',
        'active',
    ];

    protected $attributes = [
        'required_sections' => '[]',
        'active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required_sections' => 'array',
            'active' => 'boolean',
        ];
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ClinicalNote::class, 'template_id');
    }
}
