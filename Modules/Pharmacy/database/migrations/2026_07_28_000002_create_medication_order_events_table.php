<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHARMACY.G2 — the APPEND-ONLY history of a medication order (the `stay_events` recipe): one immutable row
 * per lifecycle transition (placed / held / resumed / discontinued / completed), preserving who + when +
 * why. A correction is a NEW event, never an edit — model guards + DB triggers (`SIGNAL '45000'`).
 *
 * ELECTRIC FENCE: `reason` is the clinician's own words; nothing is computed. `patient_id` is denormalized
 * for patient-scoped read-logging / scoping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_order_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id'); // denormalized for patient-scoped read logging
            $table->ulid('medication_order_id');
            $table->string('event_type'); // placed / held / resumed / discontinued / completed
            $table->string('reason')->nullable(); // the clinician's reason for this transition
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('medication_order_id')->references('id')->on('medication_orders')->cascadeOnDelete();

            $table->index(['tenant_id', 'medication_order_id']);
            $table->index(['tenant_id', 'patient_id', 'occurred_at']);
        });

        // Append-only: a medication-order event is an immutable record of fact.
        DB::unprepared(<<<'SQL'
CREATE TRIGGER medication_order_events_no_update BEFORE UPDATE ON medication_order_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'medication_order_events are append-only: UPDATE is forbidden';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER medication_order_events_no_delete BEFORE DELETE ON medication_order_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'medication_order_events are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS medication_order_events_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS medication_order_events_no_delete');
        Schema::dropIfExists('medication_order_events');
    }
};
