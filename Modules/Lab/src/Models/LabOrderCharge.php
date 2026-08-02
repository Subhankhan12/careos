<?php

namespace Modules\Lab\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * The lab-order→charge link (LAB.G6) — records that a {@see LabOrder} produced a Billing `Charge` (the test
 * fee) via the EXISTING `ChargeCaptureService` (the `EdVisitCharge` / `SurgicalCaseCharge` / `DispenseCharge`
 * precedent). The presence of any row for a lab order makes charge-a-lab-order IDEMPOTENT (billed once). NO
 * money stored — the Charge is the money (the engine owns it). `charge_id` is a SOFT ref (no FK — Lab stays
 * decoupled from Billing's tables while still USING the engine).
 *
 * FENCE: the lab fee is a plain tariff, NOT driven by the result value/abnormality — this link carries no
 * money/severity/result column.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $lab_order_id
 * @property string $charge_id
 * @property-read LabOrder|null $labOrder
 */
class LabOrderCharge extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'lab_order_id',
        'charge_id',
    ];

    public function labOrder(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class);
    }
}
