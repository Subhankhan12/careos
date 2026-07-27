<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHARMACY.G5 — the pricing link: a formulary item optionally references a Billing `TariffItem` for pricing
 * (the `DentalProcedure` priced-overlay shape; docs/HOSPITAL-PHASE2-PHARMACY-MAP.md §2.1). Pricing lives in
 * the EXISTING billing/tariff store (integer minor units, tenant-authored) — NOT duplicated in pharmacy; a
 * dispensed med is charged via the tariff item's code through `ChargeCaptureService`. A SOFT nullable ref
 * (no FK — Pharmacy's schema stays decoupled from Billing's, like `stay_id`); the link is set by
 * `PharmacyBillingService::priceItem`. NO licensed drug pricing bundled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('formulary_items', function (Blueprint $table): void {
            $table->ulid('tariff_item_id')->nullable()->after('strength'); // soft ref to a Billing TariffItem
        });
    }

    public function down(): void
    {
        Schema::table('formulary_items', function (Blueprint $table): void {
            $table->dropColumn('tariff_item_id');
        });
    }
};
