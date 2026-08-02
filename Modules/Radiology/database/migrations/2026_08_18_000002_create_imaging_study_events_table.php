<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RAD.G3 — the APPEND-ONLY imaging-study state history (the `specimen_events` / `ed_visit_events` recipe, per
 * docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md §2.2) — one immutable row for the initial `ordered` + one per state
 * transition (acquired / reported / cancelled) + an optional reason + who + when. A correction is a NEW row,
 * never an edit: model `updating`/`deleting` guards (belt) + `SIGNAL '45000'` DB triggers (suspenders).
 * Patient-scoped. No image/pixel data — the image is the PACS partner's (RAD.G6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imaging_study_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');
            $table->ulid('imaging_study_id');
            $table->string('event_type'); // ordered / acquired / reported / cancelled (1:1 with the target state)
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('performed_by');
            $table->dateTime('occurred_at'); // DATETIME (not TIMESTAMP) — engine-parity: no implicit ON UPDATE (P0P.G15)
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('imaging_study_id')->references('id')->on('imaging_studies')->cascadeOnDelete();

            $table->index(['tenant_id', 'imaging_study_id']);
        });

        // Append-only: an imaging-study state event is an immutable record of fact (belt for the model guards).
        DB::unprepared(<<<'SQL'
CREATE TRIGGER imaging_study_events_no_update BEFORE UPDATE ON imaging_study_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'imaging_study_events are append-only: UPDATE is forbidden';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER imaging_study_events_no_delete BEFORE DELETE ON imaging_study_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'imaging_study_events are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS imaging_study_events_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS imaging_study_events_no_delete');
        Schema::dropIfExists('imaging_study_events');
    }
};
