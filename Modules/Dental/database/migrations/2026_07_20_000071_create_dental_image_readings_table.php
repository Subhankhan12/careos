<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * dental_image_readings — the DENTIST'S written interpretation of a dental image (DENTAL.G8).
 * APPEND-ONLY: a reading is a clinical finding the dentist authored; a change/correction is a NEW
 * reading + a reason, never an edit (UPDATE/DELETE blocked by DB triggers). The current reading is
 * the latest row per image; history is every row.
 *
 * ELECTRIC FENCE: `reading` is FREE TEXT the DENTIST wrote — the system stores it, it never
 * generates it. There is no AI/CV analysis of the image and DELIBERATELY no detected/finding/
 * overlay/confidence/annotation-computed column. The dentist reads the image; the system records
 * what they wrote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dental_image_readings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->foreignUlid('dental_image_id')->constrained('dental_images')->cascadeOnDelete();
            $table->ulid('patient_id'); // denormalized for patient-scoped read logging
            $table->text('reading');    // the dentist's own written interpretation — never generated
            $table->string('reason')->nullable(); // why this supersedes a prior reading (a correction)
            $table->foreignId('read_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('read_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();

            $table->index(['tenant_id', 'dental_image_id', 'read_at'], 'dental_image_readings_history_idx');
        });

        DB::unprepared(<<<'SQL'
CREATE TRIGGER dental_image_readings_no_update BEFORE UPDATE ON dental_image_readings
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'dental_image_readings are append-only: a correction is a new reading, not an edit';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER dental_image_readings_no_delete BEFORE DELETE ON dental_image_readings
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'dental_image_readings are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS dental_image_readings_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS dental_image_readings_no_delete');
        Schema::dropIfExists('dental_image_readings');
    }
};
