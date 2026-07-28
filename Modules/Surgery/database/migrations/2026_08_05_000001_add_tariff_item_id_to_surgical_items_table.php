<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SURGERY.G5 — the pricing link: a surgical item (consumable / implant) optionally references a Billing
 * `TariffItem` for pricing (the `FormularyItem` / `DentalProcedure` priced-overlay shape;
 * docs/HOSPITAL-PHASE5-SURGERY-MAP.md §2.6). Pricing lives in the EXISTING billing/tariff store (integer minor
 * units, tenant-authored) — NOT duplicated in Surgery; a used item is charged via the tariff item's code
 * through `ChargeCaptureService`. A SOFT nullable ref (no FK — Surgery's schema stays decoupled from Billing's,
 * like `stay_id`); set by `SurgicalBillingService::priceItem`. NO licensed pricing bundled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surgical_items', function (Blueprint $table): void {
            $table->ulid('tariff_item_id')->nullable()->after('name'); // soft ref to a Billing TariffItem
        });
    }

    public function down(): void
    {
        Schema::table('surgical_items', function (Blueprint $table): void {
            $table->dropColumn('tariff_item_id');
        });
    }
};
