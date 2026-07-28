<?php

namespace Modules\Surgery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\LogsReads;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Surgery\Exceptions\SurgicalChecklistException;

/**
 * The APPEND-ONLY WHO-checklist completion log (SURGERY.G3) — one immutable row per confirmation of a checklist
 * item (a team member records checked / not-checked, with who + when + an optional note). A correction is a
 * NEW row (with a reason), never an edit: model `updating`/`deleting` guards (belt) + `SIGNAL '45000'` DB
 * triggers (suspenders), the `surgical_case_events` recipe. The CURRENT state of an item is its latest row.
 * `phase` + `label` are SNAPSHOTTED at confirmation. Patient-scoped.
 *
 * ELECTRIC FENCE (the crux): this RECORDS what the team confirmed. `checked` is the member's FACT; there is NO
 * verdict / passed / safe / pass_fail column — CareOS computes no safety judgment and gates no surgery.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $surgical_checklist_id
 * @property string $patient_id
 * @property string|null $template_item_id
 * @property string $phase
 * @property string $label
 * @property bool $checked
 * @property int $confirmed_by
 * @property Carbon $confirmed_at
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SurgicalChecklist|null $checklist
 */
class SurgicalChecklistItem extends Model
{
    use BelongsToTenant, HasUlids, LogsReads;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'surgical_checklist_id',
        'patient_id',
        'template_item_id',
        'phase',
        'label',
        'checked',
        'confirmed_by',
        'confirmed_at',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked' => 'boolean',
            'confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SurgicalChecklistItem $item): void {
            if (! in_array($item->phase, SurgicalChecklistTemplateItem::PHASES, true)) {
                throw SurgicalChecklistException::invalidPhase((string) $item->phase);
            }
        });

        // Append-only: an immutable record of fact (belt for the DB triggers).
        static::updating(fn () => throw SurgicalChecklistException::appendOnly());
        static::deleting(fn () => throw SurgicalChecklistException::appendOnly());
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(SurgicalChecklist::class, 'surgical_checklist_id');
    }

    protected function auditPatientId(): ?string
    {
        return $this->patient_id;
    }
}
