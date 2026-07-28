<?php

namespace Modules\Surgery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Clinical\Models\Encounter;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * The case↔Encounter link for op documentation (SURGERY.G2) — the `ward_rounds` precedent. Clinical's
 * `Encounter` is REUSED UNMODIFIED (its schema + one-open-per-practitioner invariant untouched); this
 * Surgery-side link keeps the association module-side. Each linked encounter carries a `phase` and holds a
 * sign-and-lock `ClinicalNote` authored through the EXISTING Clinical services. Tenant-scoped, fail-closed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $surgical_case_id
 * @property string $encounter_id
 * @property string $phase
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Encounter|null $encounter
 * @property-read SurgicalCase|null $surgicalCase
 */
class SurgicalCaseEncounter extends Model
{
    use BelongsToTenant, HasUlids;

    public const PHASE_PRE_OP = 'pre_op';

    public const PHASE_OPERATIVE = 'operative';

    public const PHASE_POST_OP = 'post_op';

    public const PHASES = [self::PHASE_PRE_OP, self::PHASE_OPERATIVE, self::PHASE_POST_OP];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'surgical_case_id',
        'encounter_id',
        'phase',
    ];

    protected static function booted(): void
    {
        // Both referenced tables are BelongsToTenant-scoped, so a foreign-tenant row is invisible and rejected.
        static::creating(function (SurgicalCaseEncounter $link): void {
            if (! empty($link->surgical_case_id) && ! SurgicalCase::whereKey($link->surgical_case_id)->exists()) {
                throw CrossTenantReferenceException::forAttribute('surgical_case_id', (string) $link->surgical_case_id);
            }
            if (! empty($link->encounter_id) && ! Encounter::whereKey($link->encounter_id)->exists()) {
                throw CrossTenantReferenceException::forAttribute('encounter_id', (string) $link->encounter_id);
            }
        });
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    public function surgicalCase(): BelongsTo
    {
        return $this->belongsTo(SurgicalCase::class);
    }
}
