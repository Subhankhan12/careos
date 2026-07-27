<?php

namespace Modules\Hospital\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * The idempotency-ledger row for one accrued bed-day (HOSPITAL.G6) — links a (stay, service_date)
 * to the Charge the billing engine produced. The `unique(tenant_id, stay_id, service_date)` key
 * makes `hospital:accrue-bed-days` idempotent (twice never double-charges). No money here.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $stay_id
 * @property string $service_date
 * @property string $charge_id
 */
class BedDayAccrual extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'stay_id',
        'service_date',
        'charge_id',
    ];
}
