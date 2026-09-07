<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QA-FIX.6b (P6-C2, D-209) — the APPEND-ONLY anesthesia assessment for a surgical case.
 *
 * The ASA / Mallampati classes were written straight onto `surgical_cases` with `forceFill(...)->save()`:
 * a correction OVERWROTE the previous assessment with no history, the row named only the person the
 * operator PICKED from a dropdown (never the clinician who actually entered it), and it was the one
 * write in the whole Surgery module that raised no audit event.
 *
 * THE SHAPE IS NOT NEW — it is the module's own, and the ED vertical already applies it to exactly this
 * kind of value. `ed_triages` records a NURSE-ASSIGNED acuity append-only with provenance, and its
 * docblock names `SurgicalCase::asa_class` as the same shape. `surgical_checklist_items` and
 * `surgical_case_events` are append-only the same way. This table brings the ASA up to the discipline
 * its own descendant already uses: **a correction is a NEW row, and the previous assessment survives.**
 *
 * TWO PEOPLE, TWO COLUMNS, because they can legitimately differ and must never stand in for each other
 * (the QA-FIX.2a / D-195 rule):
 *   - `assessed_by`  — the CLINICIAN whose judgment this is (a `staff_profiles` id, picked). Clinical
 *                      provenance, and what `surgical_cases.asa_assessed_by` has always meant.
 *   - `recorded_by`  — the ACTOR who entered it (a `users` id, taken from the authenticated user and
 *                      never submitted). This is the column that did not exist, which is why an
 *                      assessment could be attributed to an uninvolved colleague with no trace of who
 *                      typed it.
 *
 * `surgical_cases.asa_*` is DELIBERATELY LEFT IN PLACE as the denormalised CURRENT value — the same
 * posture as `surgical_cases.status` beside `surgical_case_events`. No historical row is rewritten
 * (the D-193 / D-197 / D-202 precedent) and no existing reader breaks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgical_case_anesthesia_assessments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');          // denormalized for patient-scoped read logging (ed_triages)
            $table->ulid('surgical_case_id');
            $table->ulid('assessed_by');         // the ANESTHETIST named (staff_profiles) — clinical provenance
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete(); // the ACTOR
            $table->dateTime('assessed_at');
            $table->string('asa_class');         // I..VI — ASSIGNED by a clinician, never computed
            $table->string('mallampati')->nullable(); // I..IV — ditto
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('surgical_case_id')->references('id')->on('surgical_cases')->cascadeOnDelete();
            $table->foreign('assessed_by')->references('id')->on('staff_profiles')->restrictOnDelete();

            $table->index(['tenant_id', 'surgical_case_id', 'assessed_at'], 'sca_assess_case_at_idx');
            $table->index(['tenant_id', 'patient_id'], 'sca_assess_patient_idx');
        });

        // Append-only: an assessment is an immutable record of a clinician's judgment at a moment. A
        // revision is a NEW row (the `ed_triages` / `surgical_checklist_items` recipe).
        DB::unprepared(<<<'SQL'
CREATE TRIGGER sca_assessments_no_update BEFORE UPDATE ON surgical_case_anesthesia_assessments
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'surgical_case_anesthesia_assessments are append-only: UPDATE is forbidden';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER sca_assessments_no_delete BEFORE DELETE ON surgical_case_anesthesia_assessments
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'surgical_case_anesthesia_assessments are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS sca_assessments_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS sca_assessments_no_delete');
        Schema::dropIfExists('surgical_case_anesthesia_assessments');
    }
};
