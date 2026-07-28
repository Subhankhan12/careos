<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SURGERY.G2 — the APPEND-ONLY surgical-case lifecycle history (the `medication_order_events` / `stay_events`
 * recipe). One immutable row per transition (pre_op / in_progress / completed / post_op / cancelled) + the
 * clinician's reason + who + when. A correction is a NEW row, never an edit — enforced by the model guards
 * (belt) + the `SIGNAL '45000'` DB triggers (suspenders). Patient-scoped for read logging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgical_case_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');        // denormalized for patient-scoped read logging
            $table->ulid('surgical_case_id');
            $table->string('event_type');       // pre_op / in_progress / completed / post_op / cancelled
            $table->string('reason')->nullable(); // the clinician's reason for this transition
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('surgical_case_id')->references('id')->on('surgical_cases')->cascadeOnDelete();

            $table->index(['tenant_id', 'surgical_case_id']);
            $table->index(['tenant_id', 'patient_id', 'occurred_at']);
        });

        // Append-only: a surgical-case event is an immutable record of fact.
        DB::unprepared(<<<'SQL'
CREATE TRIGGER surgical_case_events_no_update BEFORE UPDATE ON surgical_case_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'surgical_case_events are append-only: UPDATE is forbidden';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER surgical_case_events_no_delete BEFORE DELETE ON surgical_case_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'surgical_case_events are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS surgical_case_events_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS surgical_case_events_no_delete');
        Schema::dropIfExists('surgical_case_events');
    }
};
