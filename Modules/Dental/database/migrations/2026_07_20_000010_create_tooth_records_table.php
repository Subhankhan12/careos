<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * tooth_records — the odontogram data model (DENTAL.G1). APPEND-ONLY: each row is a
 * charting record of fact; a correction is a NEW row + a reason, never an edit
 * (UPDATE/DELETE blocked by DB triggers, portable across MariaDB 10.4 + MySQL 8 —
 * same posture as order_results / clinical notes / the financial ledgers).
 *
 * ELECTRIC FENCE (record-not-judge): the only clinical value is `charted_condition`,
 * a fact the dentist selected. There is DELIBERATELY no severity / score / risk /
 * grade / abnormal / flag / priority / recommendation column — the system records,
 * it never interprets, grades, or diagnoses (asserted by a schema fence test).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tooth_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id'); // denormalized for patient-scoped read logging
            $table->string('tooth', 2);            // FDI two-digit id
            $table->string('surface')->nullable(); // null = whole-tooth record
            $table->string('charted_condition');   // a charted FACT — no interpretation
            $table->text('note')->nullable();      // clinician free-text
            $table->string('reason')->nullable();  // why this supersedes a prior record (a correction)
            $table->foreignId('charted_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('charted_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();

            // The current odontogram is the latest row per (tenant, patient, tooth, surface);
            // history is every row for a patient's tooth, ordered by charted_at.
            $table->index(['tenant_id', 'patient_id', 'tooth', 'surface'], 'tooth_records_current_idx');
            $table->index(['tenant_id', 'patient_id', 'charted_at'], 'tooth_records_history_idx');
        });

        DB::unprepared(<<<'SQL'
CREATE TRIGGER tooth_records_no_update BEFORE UPDATE ON tooth_records
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tooth_records are append-only: UPDATE is forbidden';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER tooth_records_no_delete BEFORE DELETE ON tooth_records
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tooth_records are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS tooth_records_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS tooth_records_no_delete');
        Schema::dropIfExists('tooth_records');
    }
};
