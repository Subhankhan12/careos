<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * perio_measurements — per-tooth, per-SITE periodontal measurements within a perio exam
 * (DENTAL.G6). Six sites per tooth (the standard 6-point probing). APPEND-ONLY, like the
 * parent exam (UPDATE/DELETE blocked by DB triggers, portable MariaDB 10.4 + MySQL 8).
 *
 * ELECTRIC FENCE (record-not-judge — perio's core risk): every column here is a RAW value the
 * clinician probed — pocket depth in mm, recession in mm, bleeding-on-probing true/false, and
 * (optional) mobility / furcation on their raw index scales. There is DELIBERATELY NO periodontal
 * stage (I–IV), NO grade (A–C), NO severity, NO risk score, NO computed attachment-loss
 * "finding", NO auto-flag of a deepening site. The dentist reads the numbers and interprets;
 * the system only records them (asserted by a schema/output fence test). Attachment level, if a
 * clinician wants it, is the raw arithmetic depth + recession they read — a measurement, not a
 * stored diagnosis — so it is not persisted or labelled here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perio_measurements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->foreignUlid('perio_exam_id')->constrained('perio_exams')->cascadeOnDelete();
            $table->ulid('patient_id'); // denormalized for per-site history + patient-scoped reads
            $table->string('tooth', 2);  // FDI two-digit id (reuses ToothNotation)
            $table->string('site');      // one of PerioMeasurement::SITES (6-point probing)
            // RAW measurements — numbers as probed, never graded/classified. Nullable: a site may
            // be probed for BOP only, or a value not taken this visit.
            $table->unsignedTinyInteger('pocket_depth_mm')->nullable(); // mm, as read on the probe
            $table->smallInteger('recession_mm')->nullable();           // mm; negative = gingival overgrowth
            $table->boolean('bleeding_on_probing')->default(false);     // BOP true/false — a raw observation
            $table->unsignedTinyInteger('mobility')->nullable();        // per-tooth Miller index 0–3 (raw scale)
            $table->unsignedTinyInteger('furcation')->nullable();       // Glickman/Hamp class 0–4 (raw scale)
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();

            // A site's raw values over time across exams (tooth + site), and an exam's sites.
            $table->index(['tenant_id', 'patient_id', 'tooth', 'site'], 'perio_measurements_site_history_idx');
            $table->index(['tenant_id', 'perio_exam_id'], 'perio_measurements_exam_idx');
        });

        DB::unprepared(<<<'SQL'
CREATE TRIGGER perio_measurements_no_update BEFORE UPDATE ON perio_measurements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'perio_measurements are append-only: a correction is a new record, not an edit';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER perio_measurements_no_delete BEFORE DELETE ON perio_measurements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'perio_measurements are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS perio_measurements_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS perio_measurements_no_delete');
        Schema::dropIfExists('perio_measurements');
    }
};
