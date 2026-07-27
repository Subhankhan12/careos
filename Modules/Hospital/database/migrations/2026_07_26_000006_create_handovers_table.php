<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * handovers — the nursing shift handover for an inpatient stay (HOSPITAL.G5). A NET-NEW
 * structured SBAR artifact (Situation / Background / Assessment / Recommendation), NOT a
 * reuse of ClinicalNote (different structure + shift metadata + stay-scoped). APPEND-ONLY:
 * each shift's handover is an immutable record; a correction is a NEW handover + reason, never
 * an edit (UPDATE/DELETE blocked by DB triggers — the stay_events / tooth_records posture).
 *
 * ELECTRIC FENCE (record-not-judge): the SBAR fields capture what the OUTGOING NURSE WRITES.
 * `assessment` is the nurse's OWN written clinical assessment (a SBAR/SOAP section), NOT a
 * computed score. There is DELIBERATELY no acuity / severity / score / risk / priority / flag
 * column — the system records the handover, it never computes a patient judgment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('handovers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id'); // denormalized for patient-scoped read logging
            $table->ulid('stay_id');
            $table->foreignId('authored_by')->constrained('users')->restrictOnDelete(); // the outgoing nurse
            $table->string('shift'); // day / evening / night — an operational shift label, not a judgment
            $table->text('situation');                 // S — nurse-authored
            $table->text('background')->nullable();     // B — nurse-authored
            $table->text('assessment')->nullable();     // A — the NURSE'S OWN written assessment (not a score)
            $table->text('recommendation')->nullable(); // R — nurse-authored
            $table->string('reason')->nullable();       // why this supersedes a prior handover (a correction)
            $table->dateTime('handed_over_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('stay_id')->references('id')->on('stays')->cascadeOnDelete();

            $table->index(['tenant_id', 'stay_id']);
            $table->index(['tenant_id', 'patient_id', 'handed_over_at']);
        });

        DB::unprepared(<<<'SQL'
CREATE TRIGGER handovers_no_update BEFORE UPDATE ON handovers
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'handovers are append-only: UPDATE is forbidden';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER handovers_no_delete BEFORE DELETE ON handovers
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'handovers are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS handovers_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS handovers_no_delete');
        Schema::dropIfExists('handovers');
    }
};
