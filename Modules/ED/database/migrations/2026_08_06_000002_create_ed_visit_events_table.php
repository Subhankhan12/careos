<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ED.G1 — the APPEND-ONLY ED-visit flow history (the `surgical_case_events` / `stay_events` recipe). One
 * immutable row for arrival + one per flow transition (triaged / in_treatment / awaiting_disposition /
 * dispositioned / left_without_being_seen) + an optional reason + who + when. A correction is a NEW row, never
 * an edit — enforced by the model guards (belt) + the `SIGNAL '45000'` DB triggers (suspenders). Patient-scoped
 * for read logging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ed_visit_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');        // denormalized for patient-scoped read logging
            $table->ulid('ed_visit_id');
            $table->string('event_type');       // arrived / triaged / in_treatment / awaiting_disposition / dispositioned / left_without_being_seen
            $table->string('reason')->nullable(); // an optional reason for this flow step
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('ed_visit_id')->references('id')->on('ed_visits')->cascadeOnDelete();

            $table->index(['tenant_id', 'ed_visit_id']);
            $table->index(['tenant_id', 'patient_id', 'occurred_at']);
        });

        // Append-only: an ED-visit flow event is an immutable record of fact.
        DB::unprepared(<<<'SQL'
CREATE TRIGGER ed_visit_events_no_update BEFORE UPDATE ON ed_visit_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ed_visit_events are append-only: UPDATE is forbidden';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER ed_visit_events_no_delete BEFORE DELETE ON ed_visit_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ed_visit_events are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ed_visit_events_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS ed_visit_events_no_delete');
        Schema::dropIfExists('ed_visit_events');
    }
};
