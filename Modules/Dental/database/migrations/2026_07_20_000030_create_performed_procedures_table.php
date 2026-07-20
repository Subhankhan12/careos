<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * performed_procedures (DENTAL.G4) — a procedure the dentist ACTUALLY performed: the
 * clinical fact, tied to the resulting billing `charge` (captured through the existing G3
 * path) and the tooth/surface (from G1). APPEND-ONLY (a completed procedure is a record;
 * a correction is a new record + reason — same discipline as tooth_records / clinical
 * notes / order results). It records fact, not interpretation: no severity, score, grade,
 * or recommendation.
 *
 * A performed procedure never exists without its charge (charge_id NOT NULL): the perform
 * workflow writes the clinical record + the charge + any tooth-state change in ONE
 * transaction, so you never get an orphan on either side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performed_procedures', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');
            $table->ulid('dental_procedure_id');
            $table->ulid('charge_id'); // the resulting billing charge (always present)
            $table->string('tooth', 2)->nullable();   // FDI id (G1) where tooth-scoped
            $table->string('surface')->nullable();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('performed_at');
            $table->text('note')->nullable();
            $table->string('reason')->nullable();               // why this supersedes a prior record (a correction)
            $table->string('status')->default('completed');     // completed-only in G4 (a light planned lifecycle is deferred)
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('dental_procedure_id')->references('id')->on('dental_procedures')->restrictOnDelete();
            $table->foreign('charge_id')->references('id')->on('charges')->cascadeOnDelete();

            $table->index(['tenant_id', 'patient_id', 'performed_at'], 'performed_procedures_patient_idx');
            $table->index(['tenant_id', 'patient_id', 'tooth'], 'performed_procedures_tooth_idx');
        });

        DB::unprepared(<<<'SQL'
CREATE TRIGGER performed_procedures_no_update BEFORE UPDATE ON performed_procedures
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'performed_procedures are append-only: UPDATE is forbidden';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER performed_procedures_no_delete BEFORE DELETE ON performed_procedures
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'performed_procedures are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS performed_procedures_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS performed_procedures_no_delete');
        Schema::dropIfExists('performed_procedures');
    }
};
