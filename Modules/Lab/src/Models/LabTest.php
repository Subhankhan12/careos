<?php

namespace Modules\Lab\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Clinical\Models\OrderableItem;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * The lab-test overlay (LAB.G1) on the EXISTING Clinical `OrderableItem` — the `DentalProcedure` /
 * `SurgicalItem` precedent. A lab test IS a tenant-authored `OrderableItem` (`category='lab'`; the code/name/
 * specimen/active live there); this overlay adds ONLY the lab-specific DISPLAY reference data: `unit` +
 * `reference_range`, keyed 1:1 to the orderable. Lab REUSES Clinical's `Order`/`OrderResult` for the actual
 * order + result — this model does NOT duplicate them.
 *
 * ELECTRIC FENCE (docs/HOSPITAL-PHASE3-LAB-MAP.md §4): `reference_range` is RECORDED REFERENCE DATA the
 * clinician reads beside a result (a free-text/recorded range) — the system does NOT compute a high/low/
 * abnormal/critical flag or grade the value against it (the vitals-bands line). NO judgment column here.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $orderable_item_id
 * @property string|null $unit
 * @property string|null $reference_range
 * @property-read OrderableItem|null $orderableItem
 */
class LabTest extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'orderable_item_id',
        'unit',
        'reference_range',
    ];

    public function orderableItem(): BelongsTo
    {
        return $this->belongsTo(OrderableItem::class);
    }
}
