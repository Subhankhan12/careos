<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ed_visit_encounters (ED.G4) — the ED-SIDE link that ties an `EdVisit` to the Clinical `Encounter`s created
 * during it (the `ward_rounds` / `surgical_case_encounters` precedent, per docs/HOSPITAL-PHASE6-ED-MAP.md §2.1
 * / the Bed/Stay §2.2 decomposition). This is deliberately an ED table so **CLINICAL STAYS UNTOUCHED** — the
 * Encounter schema + its one-open-per-practitioner invariant are NOT modified. An ED treatment encounter IS a
 * reused Clinical `Encounter` (`TYPE_CONSULTATION`); this row records "this encounter belongs to this ED
 * visit." ED notes/vitals/orders hang off the Encounter through the EXISTING Clinical services (record-not-
 * judge carries through — raw vitals, sign-and-lock notes, no computed acuity/score).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ed_visit_encounters', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('ed_visit_id');
            $table->ulid('encounter_id');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('ed_visit_id')->references('id')->on('ed_visits')->cascadeOnDelete();
            $table->foreign('encounter_id')->references('id')->on('encounters')->cascadeOnDelete();

            $table->unique(['tenant_id', 'encounter_id']); // one link per encounter
            $table->index(['tenant_id', 'ed_visit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ed_visit_encounters');
    }
};
