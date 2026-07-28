<?php

namespace Modules\Surgery\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
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
 * @property bool $is_implant
 * @property string $unit
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SurgicalItem extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'code',
        'name',
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
}
