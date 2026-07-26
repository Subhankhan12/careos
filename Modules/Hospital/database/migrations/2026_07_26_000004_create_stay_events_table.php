<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * stay_events — the APPEND-ONLY ADT history of a stay (HOSPITAL.G2). Each admit /
 * transfer / discharge appends one immutable row, preserving the patient's full bed
 * journey; a correction is a NEW event, never an edit (UPDATE/DELETE blocked by DB
 * triggers, portable across MariaDB 10.4 + MySQL 8 — the tooth_records / order_results
 * posture). The mutable current state lives on `stays`; this is the record of fact.
 *
 * ELECTRIC FENCE: operational history only — bed/ward/route/disposition facts, never a
 * computed acuity/severity/triage judgment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stay_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id'); // denormalized for patient-scoped read logging
            $table->ulid('stay_id');
            $table->string('event_type');            // admitted / transferred / discharged
            $table->ulid('bed_id')->nullable();      // the bed involved (admit: claimed; transfer: new; discharge: released)
            $table->ulid('from_bed_id')->nullable(); // transfer: the bed the patient left
            $table->ulid('ward_id')->nullable();
            $table->string('reason')->nullable();
            $table->string('disposition')->nullable(); // discharge outcome (operational fact)
            $table->dateTime('occurred_at');
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('stay_id')->references('id')->on('stays')->cascadeOnDelete();

            $table->index(['tenant_id', 'stay_id']);
            $table->index(['tenant_id', 'patient_id', 'occurred_at']);
        });

        DB::unprepared(<<<'SQL'
CREATE TRIGGER stay_events_no_update BEFORE UPDATE ON stay_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stay_events are append-only: UPDATE is forbidden';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER stay_events_no_delete BEFORE DELETE ON stay_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stay_events are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS stay_events_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS stay_events_no_delete');
        Schema::dropIfExists('stay_events');
    }
};
