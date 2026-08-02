<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RAD.G5 — the imaging-order→charge link: records that an imaging order produced a Billing `Charge` (the exam
 * fee) via the EXISTING `ChargeCaptureService` (the `lab_order_charges` / `ed_visit_charges` /
 * `surgical_case_charges` precedent). `unique(tenant, charge_id)` prevents a charge being linked twice, and the
 * presence of any row for an imaging order makes charge-an-order IDEMPOTENT (billed once). NO money stored — the
 * Charge is the money (the engine owns it). `charge_id` is a SOFT ref (no FK — Radiology stays decoupled from
 * Billing's tables while still USING the engine).
 *
 * FENCE: the exam fee is a plain tariff, NOT driven by the report/finding/modality-severity — this link carries
 * no money/finding/severity column; the report is a clinical record, the fee is a tariff (kept separate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radiology_order_charges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('radiology_order_id');
            $table->ulid('charge_id'); // soft ref to a Billing Charge (no FK — Radiology stays decoupled)
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('radiology_order_id')->references('id')->on('radiology_orders')->cascadeOnDelete();

            $table->unique(['tenant_id', 'charge_id']);          // one link per charge
            $table->index(['tenant_id', 'radiology_order_id']);  // "is this imaging order charged?"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radiology_order_charges');
    }
};
