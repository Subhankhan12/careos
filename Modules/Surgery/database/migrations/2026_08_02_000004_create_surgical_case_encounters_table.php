<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SURGERY.G2 — the case↔Encounter link for op documentation (the `ward_rounds` precedent). Clinical's
 * `Encounter` is REUSED UNMODIFIED (its schema + one-open-per-practitioner invariant untouched); this
 * Surgery-side link keeps the association module-side (docs/HOSPITAL-PHASE5-SURGERY-MAP.md §2.3). Each linked
 * encounter carries a phase (pre_op / operative / post_op) + a sign-and-lock `ClinicalNote` authored through
 * the EXISTING Clinical services. `unique(tenant, encounter_id)` = one link per encounter (as `ward_rounds`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgical_case_encounters', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('surgical_case_id');
            $table->ulid('encounter_id');
            $table->string('phase'); // pre_op / operative / post_op
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('surgical_case_id')->references('id')->on('surgical_cases')->cascadeOnDelete();
            $table->foreign('encounter_id')->references('id')->on('encounters')->cascadeOnDelete();

            $table->unique(['tenant_id', 'encounter_id']); // one link per encounter (the ward_rounds shape)
            $table->index(['tenant_id', 'surgical_case_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgical_case_encounters');
    }
};
