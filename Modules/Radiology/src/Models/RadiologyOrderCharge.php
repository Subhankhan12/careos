<?php

namespace Modules\Radiology\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * The imaging-order→charge link (RAD.G5) — records that a {@see RadiologyOrder} produced a Billing `Charge`
 * (the exam fee) via the EXISTING `ChargeCaptureService` (the `LabOrderCharge` / `EdVisitCharge` precedent). The
 * presence of any row for an imaging order makes charge-an-order IDEMPOTENT (billed once). NO money stored — the
 * Charge is the money (the engine owns it). `charge_id` is a SOFT ref (no FK — Radiology stays decoupled from
 * Billing's tables while still USING the engine).
 *
 * FENCE: the exam fee is a plain tariff, NOT driven by the report/finding/severity — this link carries no
 * money/finding/severity column.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $radiology_order_id
 * @property string $charge_id
 * @property-read RadiologyOrder|null $radiologyOrder
 */
class RadiologyOrderCharge extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'radiology_order_id',
        'charge_id',
    ];

    public function radiologyOrder(): BelongsTo
    {
        return $this->belongsTo(RadiologyOrder::class);
    }
}
