<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAB.G6 — the lab-order→charge link: records that a lab order produced a Billing `Charge` (the test fee) via
 * the EXISTING `ChargeCaptureService` (the `ed_visit_charges` / `surgical_case_charges` / `dispense_charges` /
 * `bed_day_accruals` precedent). `unique(tenant, charge_id)` prevents a charge being linked twice, and the
 * presence of any row for a lab order makes charge-a-lab-order IDEMPOTENT (a lab order is billed once). NO money
 * stored — the Charge is the money (the engine owns it). `charge_id` is a SOFT ref (no FK — Lab stays decoupled
 * from Billing's tables while still USING the engine).
 *
 * FENCE: the lab fee is a plain tariff, NOT driven by the result value/abnormality — this link carries no
 * money/severity/result column; the result is a clinical record, the fee is a tariff (kept separate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_order_charges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('lab_order_id');
            $table->ulid('charge_id'); // soft ref to a Billing Charge (no FK — Lab stays decoupled)
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('lab_order_id')->references('id')->on('lab_orders')->cascadeOnDelete();

            $table->unique(['tenant_id', 'charge_id']);      // one link per charge
            $table->index(['tenant_id', 'lab_order_id']);    // "is this lab order charged?"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_order_charges');
    }
};
