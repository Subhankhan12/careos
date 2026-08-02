<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RAD.G4 — the RADIOLOGY-SIDE link tying a RAD.G3 `ImagingStudy` to the Clinical `Encounter` the radiologist's
 * report is authored on (the `ed_visit_encounters` / `ward_rounds` / `surgical_case_encounters` precedent, per
 * docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md §2.5). **CLINICAL STAYS UNTOUCHED** — the `Encounter`/`ClinicalNote`
 * schema + the sign-and-lock + one-open-per-practitioner invariants are NOT modified. A radiology report IS a
 * reused sign-and-lock `ClinicalNote` (on a `TYPE_CONSULTATION` Encounter); this row records "this report
 * encounter belongs to this study." One report-encounter per study.
 *
 * THE FENCE: the report is the radiologist's AUTHORED prose (findings + impression, written by a human) — there
 * is deliberately NO computed image finding/CAD/abnormality/confidence/auto-read column here or anywhere. "AI
 * radiology" is a hard medical-device non-goal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imaging_study_reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');        // denormalized for patient-scoped audit + scoping
            $table->ulid('imaging_study_id');
            $table->ulid('encounter_id');      // the reused Clinical Encounter the report note is authored on
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('imaging_study_id')->references('id')->on('imaging_studies')->cascadeOnDelete();
            $table->foreign('encounter_id')->references('id')->on('encounters')->cascadeOnDelete();

            $table->unique('imaging_study_id');            // one report-encounter per study
            $table->unique(['tenant_id', 'encounter_id']); // one link per encounter
            $table->index(['tenant_id', 'patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imaging_study_reports');
    }
};
