<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HOSPITAL.G7 — the discharge summary: a stay-scoped, clinician-authored SIGN-AND-LOCK clinical document
 * that closes out the inpatient episode. It is NOT a ClinicalNote (SOAP + encounter-scoped) nor an uploaded
 * Document (file/path-shaped) — it is a stay-scoped narrative, so it OWNS its table while REUSING the
 * platform patterns: the sign-and-lock discipline (draft -> finalized-immutable) mirrored on the
 * `clinical_notes` conditional-trigger precedent, `BelongsToTenant`, and patient-scoped read-logging.
 *
 * ELECTRIC FENCE: the columns are human-authored FACTS/free-text (summary + instructions) — there is
 * deliberately NO acuity/severity/score/risk/rating/outlier/readmission column, and length-of-stay is
 * DERIVED on read from the Stay (a fact), never stored or graded here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discharge_summaries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id'); // denormalized for patient-scoped read logging
            $table->ulid('stay_id');
            $table->foreignId('authored_by')->constrained('users')->restrictOnDelete(); // the drafting clinician
            $table->text('summary');                    // the clinician's episode narrative (course + outcome) — authored
            $table->text('instructions')->nullable();   // discharge instructions for the patient — authored
            $table->string('status')->default('draft'); // draft -> finalized (sign-and-lock)
            $table->dateTime('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete(); // the signing clinician
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('stay_id')->references('id')->on('stays')->cascadeOnDelete();

            // A closed episode has exactly one discharge summary.
            $table->unique(['tenant_id', 'stay_id']);
            $table->index(['tenant_id', 'patient_id']);
        });

        // Sign-and-lock: a FINALIZED summary is immutable at the DB level (the clinical_notes_signed_* precedent).
        // A draft stays editable; once finalized, UPDATE/DELETE are refused — belt for the model guard.
        DB::unprepared(<<<'SQL'
CREATE TRIGGER discharge_summaries_finalized_no_update BEFORE UPDATE ON discharge_summaries
FOR EACH ROW
BEGIN
    IF OLD.status = 'finalized' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'finalized discharge_summaries are immutable: UPDATE is forbidden';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER discharge_summaries_finalized_no_delete BEFORE DELETE ON discharge_summaries
FOR EACH ROW
BEGIN
    IF OLD.status = 'finalized' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'finalized discharge_summaries are immutable: DELETE is forbidden';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS discharge_summaries_finalized_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS discharge_summaries_finalized_no_delete');
        Schema::dropIfExists('discharge_summaries');
    }
};
