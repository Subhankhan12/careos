<?php

namespace Modules\Surgery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Billing\Models\TariffItem;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Surgery\Exceptions\SurgicalInventoryException;

/**
 * A tenant-authored SURGICAL ITEM (SURGERY.G4) — a consumable or an implant on the tenant's OWN list (MIRRORS
 * the pharmacy `FormularyItem` tenant-authored catalog; Surgery cannot import the peer Pharmacy vertical, so
 * the recipe is copied). `is_implant` flags an item that needs lot/serial/UDI traceability.
 *
 * ELECTRIC FENCE: a plain operational catalog row — no device-safety / recall-status / grade field.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $tariff_item_id
 * @property bool $is_implant
 * @property string $unit
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TariffItem|null $tariffItem
 */
class SurgicalItem extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'code',
        'name',
        'tariff_item_id',
        'is_implant',
        'unit',
        'active',
    ];

    protected $attributes = [
        'is_implant' => false,
        'active' => true,
        'unit' => 'unit',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_implant' => 'boolean',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (SurgicalItem $item): void {
            if (trim((string) $item->code) === '' || trim((string) $item->name) === '') {
                throw SurgicalInventoryException::itemCodeRequired();
            }
        });
    }

    /**
     * The Billing tariff item that prices this surgical item (SURGERY.G5) — tenant-authored pricing in the
     * existing tariff store, NOT duplicated here. Null until priced. Surgery MAY use Billing (arch boundary).
     */
    public function tariffItem(): BelongsTo
    {
        return $this->belongsTo(TariffItem::class);
    }

    public function isPriced(): bool
    {
        return $this->tariff_item_id !== null;
    }
}
