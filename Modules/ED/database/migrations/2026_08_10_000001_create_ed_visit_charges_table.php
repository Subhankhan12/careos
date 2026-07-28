<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ED.G6 — the visit→charge link: records that an ED visit produced Billing `Charge`s (the attendance/facility
 * fee + a charge per ED service) via the EXISTING `ChargeCaptureService` (the `surgical_case_charges` /
 * `dispense_charges` / `bed_day_accruals` precedent). A visit has several charges, so one row per charge;
 * `unique(tenant, charge_id)` prevents a charge being linked twice, and the presence of any row for a visit
 * makes charge-a-visit IDEMPOTENT (a visit is billed once). NO money stored — the Charge is the money (the
 * engine owns it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ed_visit_charges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('ed_visit_id');
            $table->ulid('charge_id'); // soft ref to a Billing Charge (no FK — ED stays decoupled)
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('ed_visit_id')->references('id')->on('ed_visits')->cascadeOnDelete();

            $table->unique(['tenant_id', 'charge_id']);    // one link per charge
            $table->index(['tenant_id', 'ed_visit_id']);   // "is this visit charged?"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ed_visit_charges');
    }
};
