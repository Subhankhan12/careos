<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SURGERY.G4 — IMPLANT PLACEMENT: the net-new lot/serial/UDI TRACEABILITY extension (map §2.5). Records the
 * specific implant (lot number / serial / UDI = unique device identifier) placed in a specific patient during
 * a case, so a placed implant is TRACEABLE to the patient — essential for device recalls (a regulatory /
 * patient-safety requirement). Linked to the `case_item_usage` that decremented stock. Indexed by lot + UDI
 * for the recall lookup. Append-only; patient-scoped read-logged.
 *
 * ELECTRIC FENCE: this is RECORD-KEEPING (traceability) — the system records WHICH implant went into WHICH
 * patient. It does NOT verify, grade, or compute a device-safety/recall verdict (that is a manufacturer /
 * regulatory matter): no verdict / safe / recall_status / grade column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('implant_placements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('surgical_case_id');
            $table->ulid('patient_id');
            $table->ulid('surgical_item_id');
            $table->ulid('case_item_usage_id')->nullable(); // soft link to the usage that decremented stock
            $table->string('lot_number');
            $table->string('serial_number')->nullable();
            $table->string('udi')->nullable();              // unique device identifier
            $table->foreignId('placed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('placed_at');
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('surgical_case_id')->references('id')->on('surgical_cases')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('surgical_item_id')->references('id')->on('surgical_items')->cascadeOnDelete();

            $table->index(['tenant_id', 'patient_id']);
            $table->index(['tenant_id', 'lot_number']);   // the recall lookup: lot -> patients
            $table->index(['tenant_id', 'udi']);          // the recall lookup: UDI -> patients
        });

        DB::unprepared(<<<'SQL'
CREATE TRIGGER implant_placements_no_update BEFORE UPDATE ON implant_placements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'implant_placements are append-only: UPDATE is forbidden';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER implant_placements_no_delete BEFORE DELETE ON implant_placements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'implant_placements are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS implant_placements_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS implant_placements_no_delete');
        Schema::dropIfExists('implant_placements');
    }
};
