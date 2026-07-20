<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * treatment_plan_phases (DENTAL.G5) — an ordered phase within a treatment plan (e.g.
 * "Phase 1 — stabilise", "Phase 2 — restore"). Groups the planned procedures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treatment_plan_phases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('treatment_plan_id');
            $table->string('name');
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('treatment_plan_id')->references('id')->on('treatment_plans')->cascadeOnDelete();

            $table->index(['tenant_id', 'treatment_plan_id', 'sequence'], 'treatment_plan_phases_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treatment_plan_phases');
    }
};
