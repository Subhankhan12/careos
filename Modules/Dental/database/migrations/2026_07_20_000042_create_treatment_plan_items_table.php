<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * treatment_plan_items (DENTAL.G5) — a planned procedure within a phase: the dental_procedure
 * (catalog item), the tooth/surface (where tooth-scoped), and the ESTIMATED fee.
 *
 * `estimated_fee_minor` is a SNAPSHOT taken when the plan is proposed (integer minor units,
 * from the G3 tariff fee via the existing pricing mechanism — no recompute). It is null while
 * the plan is a draft (the estimate reads the live fee for display); once proposed it is frozen
 * so a later fee-schedule edit never changes an accepted plan's agreed estimate. There is NO
 * VAT/discount column — the plan estimates the fees; VAT is applied by the billing engine when
 * the procedure is actually charged (G4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treatment_plan_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('treatment_plan_id');
            $table->ulid('treatment_plan_phase_id');
            $table->ulid('dental_procedure_id');
            $table->string('tooth', 2)->nullable();
            $table->string('surface')->nullable();
            $table->integer('estimated_fee_minor')->nullable(); // snapshot at proposal; integer minor units
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('treatment_plan_id')->references('id')->on('treatment_plans')->cascadeOnDelete();
            $table->foreign('treatment_plan_phase_id')->references('id')->on('treatment_plan_phases')->cascadeOnDelete();
            $table->foreign('dental_procedure_id')->references('id')->on('dental_procedures')->restrictOnDelete();

            $table->index(['tenant_id', 'treatment_plan_id'], 'treatment_plan_items_plan_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treatment_plan_items');
    }
};
