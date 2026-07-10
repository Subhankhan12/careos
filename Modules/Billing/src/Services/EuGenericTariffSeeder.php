<?php

namespace Modules\Billing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\SettingsService;
use Modules\Platform\Services\TenantContext;

class EuGenericTariffSeeder
{
    public const CATALOG_KEY = 'eu-generic';

    public function __construct(
        private readonly TenantContext $context,
        private readonly SettingsService $settings,
    ) {}

    public function seed(Tenant $tenant, string $validFrom = '2026-01-01'): TariffCatalog
    {
        $previousTenant = $this->context->current();
        $this->context->set($tenant);

        try {
            return DB::transaction(function () use ($validFrom): TariffCatalog {
                $catalog = TariffCatalog::query()->firstOrCreate(
                    ['key' => self::CATALOG_KEY, 'version' => 1],
                    [
                        'name' => 'EU-Generic Starter Catalog',
                        'currency' => (string) $this->settings->get('currency', 'EUR'),
                        'valid_from' => $validFrom,
                        'status' => TariffCatalog::STATUS_ACTIVE,
                        'rules' => [
                            'market_pack' => 'eu_generic',
                            'documentation_required_for' => ['HOME-60', 'CONSULT-30'],
                        ],
                    ],
                );

                foreach ($this->items() as $item) {
                    TariffItem::query()->firstOrCreate(
                        ['tariff_catalog_id' => $catalog->id, 'code' => $item['code']],
                        $item,
                    );
                }

                return $catalog->load('items');
            });
        } finally {
            if ($previousTenant !== null) {
                $this->context->set($previousTenant);
            } else {
                $this->context->forget();
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(): array
    {
        return [
            [
                'code' => 'HOME-60',
                'description' => 'Home care visit, 60 minutes',
                'unit_price_minor' => 7500,
                'vat_rate_bp' => 0,
                'unit' => 'session',
                'requires_service_documentation' => true,
                'active' => true,
            ],
            [
                'code' => 'CONSULT-30',
                'description' => 'Clinical consultation, 30 minutes',
                'unit_price_minor' => 6000,
                'vat_rate_bp' => 0,
                'unit' => 'session',
                'requires_service_documentation' => true,
                'active' => true,
            ],
            [
                'code' => 'TRAVEL-15',
                'description' => 'Travel time, 15 minute unit',
                'unit_price_minor' => 1200,
                'vat_rate_bp' => 0,
                'unit' => '15min',
                'requires_service_documentation' => false,
                'active' => true,
            ],
        ];
    }
}
