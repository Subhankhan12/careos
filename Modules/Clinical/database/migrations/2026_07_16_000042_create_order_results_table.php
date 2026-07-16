<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order results — APPEND-ONLY (a result is a record of fact; corrections are new
 * result rows + a note, never edits). Stored RAW: result_value only, with NO
 * interpretation fields (no range/flag/abnormal/score) — same posture as vitals
 * (D-D3). Reviewing is on the ORDER, not by editing a result (P0P.G11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_results', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('order_id');
            $table->ulid('patient_id'); // denormalized for patient-scoped read logging
            $table->text('result_value')->nullable(); // raw, as entered — no interpretation
            $table->ulid('result_document_id')->nullable(); // link to a D.4 document (scanned report/PDF)
            $table->foreignId('entered_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('entered_at');
            $table->string('source')->default('manual'); // manual / imported — only 'manual' is used now
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('result_document_id')->references('id')->on('documents')->nullOnDelete();

            $table->index(['tenant_id', 'order_id']);
        });

        DB::unprepared(<<<'SQL'
CREATE TRIGGER order_results_no_update BEFORE UPDATE ON order_results
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'order_results are append-only: UPDATE is forbidden';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER order_results_no_delete BEFORE DELETE ON order_results
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'order_results are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS order_results_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS order_results_no_delete');
        Schema::dropIfExists('order_results');
    }
};
