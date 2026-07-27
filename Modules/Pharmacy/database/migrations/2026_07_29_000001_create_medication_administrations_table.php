<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHARMACY.G3 — the eMAR: an APPEND-ONLY medication ADMINISTRATION event against a G2 medication order
 * (docs/HOSPITAL-PHASE2-PHARMACY-MAP.md §2.4). One immutable row per administration the nurse records —
 * a correction is a NEW row (model guards + DB triggers, the `medication_order_events` recipe).
 *
 * ELECTRIC FENCE (record-not-judge): `outcome` (given / held / refused) is a FACT the NURSE records — the
 * system computes nothing. There is deliberately NO computed-safety / verdict / severity / score / graded-
 * flag column; `scheduled_at` vs `administered_at` is a raw time comparison the UI renders (late/missed is
 * an elapsed FACT, never a system grade). Medication-safety judgment lives ONLY behind the certified-partner
 * seam (called advisorily at the administration point), never on this row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_administrations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id'); // denormalized for patient-scoped read logging
            $table->ulid('medication_order_id');
            $table->foreignId('administered_by')->constrained('users')->restrictOnDelete(); // the administering nurse
            $table->string('outcome');                     // given / held / refused — the nurse's recorded FACT
            $table->dateTime('administered_at');           // when the nurse recorded it
            $table->dateTime('scheduled_at')->nullable();  // the due/scheduled time (null for PRN/unscheduled)
            $table->string('dose_amount')->nullable();     // the dose actually given (null for held/refused)
            $table->string('dose_unit')->nullable();
            $table->string('reason')->nullable();          // for held/refused — the nurse's own words
            $table->ulid('stay_id')->nullable();           // SOFT ref to a Hospital stay (inpatient); no FK
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('medication_order_id')->references('id')->on('medication_orders')->cascadeOnDelete();

            // Explicit short index names (the auto-generated names exceed MySQL's 64-char identifier limit).
            $table->index(['tenant_id', 'medication_order_id', 'administered_at'], 'med_admin_order_at_idx');
            $table->index(['tenant_id', 'patient_id', 'administered_at'], 'med_admin_patient_at_idx');
        });

        // Append-only: an administration is an immutable clinical event.
        DB::unprepared(<<<'SQL'
CREATE TRIGGER medication_administrations_no_update BEFORE UPDATE ON medication_administrations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'medication_administrations are append-only: UPDATE is forbidden';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER medication_administrations_no_delete BEFORE DELETE ON medication_administrations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'medication_administrations are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS medication_administrations_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS medication_administrations_no_delete');
        Schema::dropIfExists('medication_administrations');
    }
};
