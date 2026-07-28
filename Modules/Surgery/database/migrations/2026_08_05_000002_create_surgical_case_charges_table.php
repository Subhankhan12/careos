<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SURGERY.G5 — the case→charge link: records that a surgical case produced Billing `Charge`s (procedure +
 * theatre-time + consumables/implants) via the EXISTING `ChargeCaptureService` (the `dispense_charges` /
 * `bed_day_accruals` precedent). A case has several charges, so one row per charge; `unique(tenant, charge_id)`
 * prevents a charge being linked twice, and the presence of any row for a case makes charge-a-case IDEMPOTENT
 * (a case is billed once). NO money stored — the Charge is the money (the engine owns it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgical_case_charges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('surgical_case_id');
            $table->ulid('charge_id'); // soft ref to a Billing Charge (no FK — Surgery stays decoupled)
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('surgical_case_id')->references('id')->on('surgical_cases')->cascadeOnDelete();

            $table->unique(['tenant_id', 'charge_id']);       // one link per charge
            $table->index(['tenant_id', 'surgical_case_id']); // "is this case charged?"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgical_case_charges');
    }
};
