<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Platform\Concerns\BelongsToTenant;
use Modules\Platform\Exceptions\CrossTenantReferenceException;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $tariff_catalog_id
 * @property string $code
 * @property string $description
 * @property int $unit_price_minor
 * @property int $vat_rate_bp
 * @property string|null $unit
 * @property bool $requires_service_documentation
 * @property bool $active
 */
class TariffItem extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tariff_catalog_id',
        'code',
        'description',
        'unit_price_minor',
        'vat_rate_bp',
        'unit',
        'requires_service_documentation',
        'active',
    ];

    protected $attributes = [
        'vat_rate_bp' => 0,
        'requires_service_documentation' => false,
        'active' => true,
    ];

    protected static function booted(): void
    {
        static::creating(fn (TariffItem $item) => $item->assertTenantReferences());
        static::updating(function (TariffItem $item): void {
            if ($item->isDirty('tariff_catalog_id')) {
                $item->assertTenantReferences();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price_minor' => 'integer',
            'vat_rate_bp' => 'integer',
            'requires_service_documentation' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(TariffCatalog::class, 'tariff_catalog_id');
    }

    private function assertTenantReferences(): void
    {
        if (! TariffCatalog::query()->whereKey($this->tariff_catalog_id)->exists()) {
            throw CrossTenantReferenceException::forAttribute('tariff_catalog_id', (string) $this->tariff_catalog_id);
        }
    }
}
