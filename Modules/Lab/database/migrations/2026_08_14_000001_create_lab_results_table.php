<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAB.G4 — the thin lab-RESULT overlay linking the EXISTING Clinical `OrderResult` to the LAB.G3 specimen that
 * produced it (per docs/HOSPITAL-PHASE3-LAB-MAP.md §2.5). A lab result IS a Clinical `OrderResult` (REUSED via
 * `OrderService::recordResult` — append-only, raw, `source=manual`); this overlay adds ONLY the one lab-specific
 * fact Clinical's `OrderResult` doesn't carry: WHICH specimen produced the result. **Clinical's `OrderResult`
 * is NOT modified.** It is NOT a parallel result entity — it carries NO value (the raw value lives on the reused
 * `OrderResult`).
 *
 * THE FENCE (the sharpest in lab — docs/HOSPITAL-PHASE3-LAB-MAP.md §4): there is deliberately NO
 * abnormal/high/low/critical/flag/grade/interpretation/delta column here (nor on `OrderResult`). The reference
 * range is DISPLAYED reference data (read from the LAB.G1 catalog at presentation), never a computed threshold
 * this table grades a value against. The raw value + the displayed range are FACTS the clinician reads; the
 * system computes NO verdict.
 *
 * APPEND-ONLY (a result-link record — model guards + `SIGNAL '45000'` DB triggers, the `order_results` recipe):
 * a correction is a NEW `OrderResult` (never an edit). Patient-scoped for read logging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_results', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');       // denormalized for patient-scoped read logging + scoping
            $table->ulid('order_result_id');  // the Clinical OrderResult (the lab result IS this — reused, raw)
            $table->ulid('specimen_id');      // the LAB.G3 specimen that produced this result (the net-new link)
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('order_result_id')->references('id')->on('order_results')->cascadeOnDelete();
            $table->foreign('specimen_id')->references('id')->on('specimens')->cascadeOnDelete();

            $table->unique('order_result_id'); // one lab overlay per Clinical OrderResult
            $table->index(['tenant_id', 'patient_id']);
            $table->index('specimen_id');
        });

        // Append-only: a lab-result link is an immutable record of fact (belt for the model guards).
        DB::unprepared(<<<'SQL'
CREATE TRIGGER lab_results_no_update BEFORE UPDATE ON lab_results
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lab_results are append-only: UPDATE is forbidden';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER lab_results_no_delete BEFORE DELETE ON lab_results
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lab_results are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS lab_results_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS lab_results_no_delete');
        Schema::dropIfExists('lab_results');
    }
};
