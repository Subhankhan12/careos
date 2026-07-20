<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * dental_procedures (DENTAL.G3) — the tenant's dental fee schedule, as a THIN OVERLAY on
 * the existing Billing tariff engine. A dental procedure IS a `tariff_items` row (in the
 * tenant's dedicated dental `tariff_catalogs`), which holds the code / name / FEE / VAT —
 * so pricing lives entirely in the tested billing store and a charge snapshots it through
 * the existing ChargeCaptureService (NO dental pricing logic). This overlay adds only the
 * DENTAL-specific `tooth_scoped` flag, keyed 1:1 to the tariff item.
 *
 * NO licensed code set (CDT / SSO point values) is bundled — the catalog is tenant-authored;
 * a small generic starter template is seedable + editable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dental_procedures', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('tariff_item_id'); // the priced twin (code/name/fee/vat/active live here)
            $table->boolean('tooth_scoped')->default(false); // dental-specific: applies to a tooth/surface
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('tariff_item_id')->references('id')->on('tariff_items')->cascadeOnDelete();
            $table->unique('tariff_item_id'); // one dental procedure per tariff item
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dental_procedures');
    }
};
