<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('encounter_id');
            $table->ulid('patient_id');
            $table->ulid('author_id');
            $table->text('subjective')->nullable();
            $table->text('objective')->nullable();
            $table->text('assessment')->nullable();
            $table->text('plan')->nullable();
            $table->ulid('template_id')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('signed_at')->nullable();
            $table->unsignedBigInteger('signed_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->ulid('supersedes_id')->nullable();
            $table->text('amendment_reason')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('encounter_id')->references('id')->on('encounters')->restrictOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            $table->foreign('author_id')->references('id')->on('staff_profiles')->restrictOnDelete();
            $table->foreign('template_id')->references('id')->on('note_templates')->nullOnDelete();
            $table->foreign('signed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('supersedes_id')->references('id')->on('clinical_notes')->restrictOnDelete();

            $table->index(['tenant_id', 'encounter_id']);
            $table->index(['tenant_id', 'patient_id', 'created_at']);
            $table->index(['tenant_id', 'supersedes_id']);
        });

        DB::unprepared(<<<'SQL'
CREATE TRIGGER clinical_notes_signed_no_update BEFORE UPDATE ON clinical_notes
FOR EACH ROW
BEGIN
    IF OLD.status = 'signed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'signed clinical_notes are immutable: UPDATE is forbidden';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER clinical_notes_signed_no_delete BEFORE DELETE ON clinical_notes
FOR EACH ROW
BEGIN
    IF OLD.status = 'signed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'signed clinical_notes are immutable: DELETE is forbidden';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS clinical_notes_signed_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS clinical_notes_signed_no_delete');
        Schema::dropIfExists('clinical_notes');
    }
};
