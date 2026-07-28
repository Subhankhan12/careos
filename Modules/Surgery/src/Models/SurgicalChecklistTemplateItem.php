<?php

namespace Modules\Surgery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Surgery\Exceptions\SurgicalChecklistException;

/**
 * A tenant-authored WHO Surgical Safety Checklist TEMPLATE item (SURGERY.G3) — one line the team confirms in a
 * given WHO phase (sign_in / time_out / sign_out). A MUTABLE config catalog (the `formulary` / `DentalProcedure`
 * tenant-authored discipline), seeded with the standard WHO items as an editable starter — NOT a licensed set.
 *
 * ELECTRIC FENCE: a plain checklist label + display order — no verdict / weight / safety-score field.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $phase
 * @property string $label
 * @property int $display_order
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SurgicalChecklistTemplateItem extends Model
{
    use BelongsToTenant, HasUlids;

    // The three WHO phases — the canonical, non-negotiable structure of the checklist (not a judgment).
    public const PHASE_SIGN_IN = 'sign_in';       // before induction of anaesthesia

    public const PHASE_TIME_OUT = 'time_out';     // before skin incision

    public const PHASE_SIGN_OUT = 'sign_out';     // before the team leaves theatre

    public const PHASES = [self::PHASE_SIGN_IN, self::PHASE_TIME_OUT, self::PHASE_SIGN_OUT];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'phase',
        'label',
        'display_order',
        'active',
    ];

    protected $attributes = [
        'active' => true,
        'display_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (SurgicalChecklistTemplateItem $item): void {
            if (! in_array($item->phase, self::PHASES, true)) {
                throw SurgicalChecklistException::invalidPhase((string) $item->phase);
            }
            if (trim((string) $item->label) === '') {
                throw SurgicalChecklistException::labelRequired();
            }
        });
    }
}
