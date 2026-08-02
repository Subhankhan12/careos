<?php

namespace Modules\Radiology\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Clinical\Models\OrderableItem;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * The imaging-exam overlay (RAD.G1) on the EXISTING Clinical `OrderableItem` — the `LabTest` / `DentalProcedure`
 * / `SurgicalItem` precedent. An imaging exam IS a tenant-authored `OrderableItem` (`category='imaging'`; the
 * code/name live there, and the **modality** lives in the existing `specimen_or_modality` field); this overlay
 * adds ONLY the imaging-specific facts `body_part` + `contrast`, keyed 1:1 to the orderable. Radiology REUSES
 * Clinical's `Order`/`ClinicalNote`/`Document` for the actual order + report + image — this model does NOT
 * duplicate them.
 *
 * ELECTRIC FENCE (docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md §4): the exam catalog is RECORDED reference data
 * (modality/body-part the clinician/radiographer reads) — there is deliberately NO computed image
 * finding/CAD/abnormality/confidence column here or anywhere. The radiologist AUTHORS the report (RAD.G4); the
 * system interprets no image.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $orderable_item_id
 * @property string|null $body_part
 * @property bool $contrast
 * @property-read OrderableItem|null $orderableItem
 */
class RadiologyExam extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'orderable_item_id',
        'body_part',
        'contrast',
    ];

    protected $attributes = [
        'contrast' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['contrast' => 'boolean'];
    }

    public function orderableItem(): BelongsTo
    {
        return $this->belongsTo(OrderableItem::class);
    }
}
