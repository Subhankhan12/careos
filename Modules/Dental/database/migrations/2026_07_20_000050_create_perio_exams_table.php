<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * perio_exams — a point-in-time periodontal charting session for a patient (DENTAL.G6).
 * APPEND-ONLY: a perio exam is a clinical record. A re-exam is a NEW exam; corrections are
 * new records + a reason, never edits (UPDATE/DELETE blocked by DB triggers, portable across
 * MariaDB 10.4 + MySQL 8 — the same posture as tooth_records / order_results / vitals).
 *
 * ELECTRIC FENCE (record-not-judge): this header carries only WHO charted, WHEN, and an
 * optional note. There is DELIBERATELY no staging, grade, severity, risk, or classification —
 * a perio exam records the raw per-site measurements (see perio_measurements); the dentist
 * interprets them, the system never does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perio_exams', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id'); // denormalized for patient-scoped read logging
            $table->foreignId('examined_by')->constrained('users')->restrictOnDelete();
            $table->date('exam_date');
            $table->text('note')->nullable(); // clinician free-text
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();

            // A patient's perio exams over time, most recent first.
            $table->index(['tenant_id', 'patient_id', 'exam_date'], 'perio_exams_history_idx');
        });

        DB::unprepared(<<<'SQL'
CREATE TRIGGER perio_exams_no_update BEFORE UPDATE ON perio_exams
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'perio_exams are append-only: a re-exam is a new exam, not an edit';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER perio_exams_no_delete BEFORE DELETE ON perio_exams
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'perio_exams are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS perio_exams_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS perio_exams_no_delete');
        Schema::dropIfExists('perio_exams');
    }
};
