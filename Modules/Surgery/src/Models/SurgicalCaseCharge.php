<?php

namespace Modules\Surgery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * The case→charge link (SURGERY.G5) — records that a {@see SurgicalCase} produced a Billing `Charge`
 * (procedure / theatre-time / consumable / implant) via the EXISTING `ChargeCaptureService`. The presence of
 * any row for a case makes charge-a-case idempotent. NO money stored — the Charge is the money (the
 * `dispense_charges` / `bed_day_accruals` precedent).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $surgical_case_id
 * @property string $charge_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SurgicalCaseCharge extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'surgical_case_id',
        'charge_id',
    ];

    public function surgicalCase(): BelongsTo
    {
        return $this->belongsTo(SurgicalCase::class);
    }
}
