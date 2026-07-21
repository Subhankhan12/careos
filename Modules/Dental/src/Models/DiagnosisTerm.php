<?php

namespace Modules\Dental\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * One entry in the tenant's OWN diagnosis pick-list (DENTAL.G7) — a convenience term the dentist
 * can choose from when recording a diagnosis. Tenant-authored, exactly like the procedure catalog;
 * NO licensed diagnostic code set is bundled.
 *
 * ELECTRIC FENCE: this is a flat term with a label and an active flag — there is NO rank,
 * likelihood, confidence, score, or "suggested"/"differential" ordering. The list is never sorted
 * or filtered by any computed judgment; the dentist picks from their own terms.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $label
 * @property bool $is_active
 * @property int $created_by
 */
class DiagnosisTerm extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'label',
        'is_active',
        'created_by',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
