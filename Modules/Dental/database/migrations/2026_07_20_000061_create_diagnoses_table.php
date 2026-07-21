<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * diagnoses — a clinical diagnosis the DENTIST entered (DENTAL.G7). APPEND-ONLY: a diagnosis is
 * a clinical record; a change (e.g. provisional → confirmed, or a correction) is a NEW record +
 * a reason, never an edit (UPDATE/DELETE blocked by DB triggers, portable MariaDB 10.4 + MySQL 8
 * — the same posture as tooth_records / perio_measurements / the clinical ledgers).
 *
 * ELECTRIC FENCE — THE SHARPEST IN THE DENTAL VERTICAL (do not compromise): every row here is
 * something the DENTIST decided and typed. The system does NOT propose a diagnosis, does NOT
 * rank a differential, does NOT suggest what the condition is, does NOT auto-populate a diagnosis
 * from the charting/perio/imaging, and does NOT compute a likelihood. There is NO AI in this
 * path at all. `status` (provisional / confirmed / ruled_out) is the DENTIST'S determination —
 * the system records the value the dentist set; it never decides or suggests it. There is
 * DELIBERATELY no suggested / proposed / differential / likelihood / confidence / ranked / ai /
 * recommended column (asserted by a schema/output fence test). `diagnosis_term_id` is merely
 * provenance — WHICH of the tenant's own pick-list terms the dentist chose (null = free text).
 * `label` stores the diagnosis text on the record so it is immutable even if the term is edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnoses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id'); // denormalized for patient-scoped read logging
            $table->foreignId('diagnosed_by')->constrained('users')->restrictOnDelete();
            // Provenance only: which tenant pick-list term the dentist chose (null = free text).
            $table->foreignUlid('diagnosis_term_id')->nullable()->constrained('diagnosis_terms')->nullOnDelete();
            $table->string('label');               // the diagnosis text the dentist entered/chose
            $table->string('tooth', 2)->nullable(); // FDI id it relates to (optional)
            $table->string('surface')->nullable();  // optional surface (FDI)
            $table->string('status');               // provisional | confirmed | ruled_out — DENTIST-set
            $table->text('findings')->nullable();   // supporting notes/findings the dentist references
            $table->string('reason')->nullable();   // why this supersedes a prior record (a change)
            $table->dateTime('diagnosed_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();

            // A patient's diagnoses over time (history is every row, most recent first).
            $table->index(['tenant_id', 'patient_id', 'diagnosed_at'], 'diagnoses_history_idx');
        });

        DB::unprepared(<<<'SQL'
CREATE TRIGGER diagnoses_no_update BEFORE UPDATE ON diagnoses
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'diagnoses are append-only: a change is a new record, not an edit';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER diagnoses_no_delete BEFORE DELETE ON diagnoses
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'diagnoses are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS diagnoses_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS diagnoses_no_delete');
        Schema::dropIfExists('diagnoses');
    }
};
