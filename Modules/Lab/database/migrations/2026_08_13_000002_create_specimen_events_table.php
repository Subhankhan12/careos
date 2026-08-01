<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAB.G3 — the APPEND-ONLY specimen state history (the `ed_visit_events` / `stay_events` recipe). One immutable
 * row for collection + one per state transition (in_lab / resulted / rejected) + an optional reason + who +
 * when. A correction is a NEW row, never an edit — enforced by the model guards (belt) + the `SIGNAL '45000'`
 * DB triggers (suspenders). Patient-scoped for read logging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specimen_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id'); // denormalized for patient-scoped read logging
            $table->ulid('specimen_id');
            $table->string('event_type'); // collected / in_lab / resulted / rejected
            $table->string('reason')->nullable(); // required on rejection
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('specimen_id')->references('id')->on('specimens')->cascadeOnDelete();

            $table->index(['tenant_id', 'specimen_id']);
            $table->index(['tenant_id', 'patient_id', 'occurred_at']);
        });

        // Append-only: a specimen state event is an immutable record of fact.
        DB::unprepared(<<<'SQL'
CREATE TRIGGER specimen_events_no_update BEFORE UPDATE ON specimen_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'specimen_events are append-only: UPDATE is forbidden';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER specimen_events_no_delete BEFORE DELETE ON specimen_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'specimen_events are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS specimen_events_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS specimen_events_no_delete');
        Schema::dropIfExists('specimen_events');
    }
};
