<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHARMACY.G5 — the dispense→charge link: records that a G4 dispense produced a Billing `Charge` (via the
 * EXISTING `ChargeCaptureService`). The `bed_day_accruals` / `DentalProcedureCharge` precedent. `unique(tenant,
 * dispense_id)` makes charge-on-dispense IDEMPOTENT (a dispense is charged once). NO money stored here — the
 * Charge is the money (the engine owns it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispense_charges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('dispense_id');
            $table->ulid('charge_id'); // soft ref to a Billing Charge (no FK — Pharmacy stays decoupled)
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('dispense_id')->references('id')->on('dispenses')->cascadeOnDelete();

            // One charge per dispense — charge-on-dispense is idempotent.
            $table->unique(['tenant_id', 'dispense_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispense_charges');
    }
};
