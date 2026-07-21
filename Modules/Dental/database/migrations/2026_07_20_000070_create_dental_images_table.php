<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * dental_images — dental METADATA over an image asset that is stored through the EXISTING clinical
 * document storage (DENTAL.G8). The file itself lives on the private disk via `DocumentService`
 * (tenant-prefixed path, MIME/size validated, no public URL); `document_id` references that stored
 * asset. This row adds the dental facts: the image type, the tooth/region it relates to, and who
 * captured it when. The dentist's READING (interpretation) is a separate append-only record
 * (`dental_image_readings`).
 *
 * APPEND-ONLY: the image asset is immutable once uploaded (UPDATE/DELETE blocked by DB triggers,
 * portable MariaDB 10.4 + MySQL 8) — you do not edit a captured image or its metadata; a new
 * capture is a new row.
 *
 * ELECTRIC FENCE (imaging's risk): there is DELIBERATELY no ai / finding / detected / overlay /
 * annotation / confidence / score column — the system stores and displays the image and the
 * dentist's written reading; it NEVER detects caries, flags pathology, overlays AI findings, or
 * computes anything about the image. There is no CV/AI analysis of the pixels anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dental_images', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id'); // denormalized for patient-scoped read logging
            $table->foreignUlid('document_id')->constrained('documents')->cascadeOnDelete(); // the stored asset
            $table->string('image_type');           // bitewing | periapical | panoramic | photo | scan
            $table->string('tooth', 2)->nullable(); // FDI id it relates to (optional)
            $table->string('region')->nullable();   // free-text region (e.g. "upper right quadrant"), optional
            $table->dateTime('captured_at');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();

            $table->index(['tenant_id', 'patient_id', 'captured_at'], 'dental_images_gallery_idx');
        });

        DB::unprepared(<<<'SQL'
CREATE TRIGGER dental_images_no_update BEFORE UPDATE ON dental_images
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'dental_images are immutable: a captured image cannot be edited';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER dental_images_no_delete BEFORE DELETE ON dental_images
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'dental_images are immutable: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS dental_images_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS dental_images_no_delete');
        Schema::dropIfExists('dental_images');
    }
};
