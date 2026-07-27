<?php

namespace Modules\Pharmacy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * The dispense→charge link (PHARMACY.G5) — records that a G4 {@see Dispense} produced a Billing `Charge`
 * (via the EXISTING `ChargeCaptureService`). `unique(tenant, dispense_id)` makes charge-on-dispense
 * idempotent. NO money stored — the Charge is the money (the `bed_day_accruals` / `DentalProcedureCharge`
 * precedent).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $dispense_id
 * @property string $charge_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DispenseCharge extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'dispense_id',
        'charge_id',
    ];

    public function dispense(): BelongsTo
    {
        return $this->belongsTo(Dispense::class);
    }
}
