<?php

namespace Modules\Billing\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Modules\Billing\Exceptions\TariffNotFoundForDateException;
use Modules\Billing\Models\TariffCatalog;
use Modules\Billing\Models\TariffItem;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\TenantContext;

class TariffResolver
{
    public function __construct(private readonly TenantContext $context) {}

    public function resolve(Tenant $tenant, string $code, CarbonInterface|string $serviceDate): TariffItem
    {
        $date = $serviceDate instanceof CarbonInterface
            ? Carbon::instance($serviceDate)->toDateString()
            : Carbon::parse($serviceDate)->toDateString();

        $previousTenant = $this->context->current();
        $this->context->set($tenant);

        try {
            $item = TariffItem::query()
                ->select('tariff_items.*')
                ->join('tariff_catalogs', 'tariff_catalogs.id', '=', 'tariff_items.tariff_catalog_id')
                ->where('tariff_items.code', $code)
                ->where('tariff_items.active', true)
                ->where('tariff_catalogs.status', TariffCatalog::STATUS_ACTIVE)
                ->whereDate('tariff_catalogs.valid_from', '<=', $date)
                ->where(function ($query) use ($date): void {
                    $query->whereNull('tariff_catalogs.valid_to')
                        ->orWhereDate('tariff_catalogs.valid_to', '>=', $date);
                })
                ->orderByDesc('tariff_catalogs.valid_from')
                ->first();
        } finally {
            if ($previousTenant !== null) {
                $this->context->set($previousTenant);
            } else {
                $this->context->forget();
            }
        }

        if ($item === null) {
            throw TariffNotFoundForDateException::forCode($code, $date);
        }

        return $item;
    }
}
