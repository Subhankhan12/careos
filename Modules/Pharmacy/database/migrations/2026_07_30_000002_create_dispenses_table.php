<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHARMACY.G4 — a DISPENSING event (append-only): a pharmacist dispensed a quantity of a medication against
 * a G2 medication order, decrementing stock. One immutable row per dispense (model guards + DB triggers,
 * the `medication_order_events` recipe); a correction is a NEW dispense / adjustment, never an edit.
 * Patient-scoped read-logged. `stay_id` is a SOFT nullable inpatient ref (no FK).
 *
 * ELECTRIC FENCE: a dispense is an operational fact (who dispensed what, when, how much). No safety judgment
 * is computed here — medication safety is the orders/administration seam, not dispensing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispenses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id'); // denormalized for patient-scoped read logging
            $table->ulid('medication_order_id');
            $table->ulid('formulary_item_id');
            $table->integer('quantity');
            $table->foreignId('dispensed_by')->constrained('users')->restrictOnDelete(); // the dispensing pharmacist
            $table->dateTime('dispensed_at');
            $table->ulid('stay_id')->nullable(); // SOFT inpatient ref; no FK
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('medication_order_id')->references('id')->on('medication_orders')->cascadeOnDelete();
            $table->foreign('formulary_item_id')->references('id')->on('formulary_items')->cascadeOnDelete();

            $table->index(['tenant_id', 'patient_id', 'dispensed_at']);
            $table->index(['tenant_id', 'medication_order_id']);
        });

        // Append-only: a dispense is an immutable operational event.
        DB::unprepared(<<<'SQL'
CREATE TRIGGER dispenses_no_update BEFORE UPDATE ON dispenses
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'dispenses are append-only: UPDATE is forbidden';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER dispenses_no_delete BEFORE DELETE ON dispenses
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'dispenses are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS dispenses_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS dispenses_no_delete');
        Schema::dropIfExists('dispenses');
    }
};
