<?php

namespace Modules\ED\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * The ED-visit→charge link (ED.G6) — records that an `EdVisit` produced Billing `Charge`s (attendance +
 * services) via the EXISTING `ChargeCaptureService` (the `SurgicalCaseCharge` / `DispenseCharge` precedent).
 * A visit has several charges, so one row per charge; the presence of any row makes charge-a-visit IDEMPOTENT.
 * NO money stored — the Charge is the money (the engine owns it). `charge_id` is a SOFT ref (no FK — ED stays
 * decoupled from Billing's tables while still USING the engine).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $ed_visit_id
 * @property string $charge_id
 * @property-read EdVisit|null $edVisit
 */
class EdVisitCharge extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'ed_visit_id',
        'charge_id',
    ];

    public function edVisit(): BelongsTo
    {
        return $this->belongsTo(EdVisit::class);
    }
}
