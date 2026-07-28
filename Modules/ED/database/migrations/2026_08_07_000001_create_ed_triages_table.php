<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ED.G2 — the TRIAGE record for an ED visit. The triage nurse's arrival assessment: the presenting complaint
 * + the ASSIGNED acuity level (the value the NURSE selected applying a protocol with their own judgment) +
 * provenance (which nurse, which scale). Raw vitals at triage reuse the EXISTING `vitals` store (no new
 * table). APPEND-ONLY (a re-triage is a NEW row; the triage history is preserved) — model guards + the
 * `SIGNAL '45000'` DB triggers, the `ed_visit_events` recipe. Patient-scoped for read logging.
 *
 * ELECTRIC FENCE (the vertical's crux — docs/HOSPITAL-PHASE6-ED-MAP.md §3): the acuity is **ASSIGNED**, never
 * COMPUTED. `acuity_level` is the value the nurse selected (a recorded fact, like `surgical_cases.asa_class`
 * and `stays.admission_type`); `acuity_scale` + `triaged_by` are provenance. There is deliberately NO
 * suggested/computed acuity, NO score, NO severity/priority/deterioration column — a COMPUTED triage acuity is
 * a regulated medical device (the fence line), a certified-partner / permanent-non-goal that lives ONLY behind
 * the empty `TriageAcuityProvider` seam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ed_triages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');            // denormalized for patient-scoped read logging
            $table->ulid('ed_visit_id');
            $table->ulid('triaged_by');            // the triage NURSE (staff_profiles) — acuity provenance
            $table->dateTime('triaged_at');
            $table->string('presenting_complaint'); // the complaint recorded at triage (may refine arrival)
            $table->string('acuity_scale');         // ESI / MANCHESTER / CTAS — the scale used (provenance)
            $table->string('acuity_level');         // the level the NURSE ASSIGNED (a recorded fact, not computed)
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('ed_visit_id')->references('id')->on('ed_visits')->cascadeOnDelete();
            $table->foreign('triaged_by')->references('id')->on('staff_profiles')->restrictOnDelete();

            $table->index(['tenant_id', 'ed_visit_id', 'triaged_at']);
            $table->index(['tenant_id', 'patient_id']);
        });

        // Append-only: a triage record is an immutable record of fact (a re-triage is a NEW row).
        DB::unprepared(<<<'SQL'
CREATE TRIGGER ed_triages_no_update BEFORE UPDATE ON ed_triages
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ed_triages are append-only: UPDATE is forbidden';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER ed_triages_no_delete BEFORE DELETE ON ed_triages
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ed_triages are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ed_triages_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS ed_triages_no_delete');
        Schema::dropIfExists('ed_triages');
    }
};
