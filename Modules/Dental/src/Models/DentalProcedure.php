<?php

namespace Modules\Dental\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Billing\Models\TariffItem;
use Modules\Platform\Concerns\BelongsToTenant;

/**
 * A dental procedure — a THIN overlay on a Billing `tariff_items` row. The tariff item
 * holds all economics (code, description/name, unit_price_minor = the fee, vat_rate_bp,
 * active); this overlay adds only the dental-specific `tooth_scoped` flag. Pricing lives
 * in the tested billing store; charging snapshots it through ChargeCaptureService.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $tariff_item_id
 * @property bool $tooth_scoped
 */
class DentalProcedure extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tariff_item_id',
        'tooth_scoped',
    ];

    protected $attributes = [
        'tooth_scoped' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tooth_scoped' => 'boolean'];
    }

    /**
     * @return BelongsTo<TariffItem, $this>
     */
    public function tariffItem(): BelongsTo
    {
        return $this->belongsTo(TariffItem::class);
    }
}
