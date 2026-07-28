<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SURGERY.G4 — an item USED/placed in a surgical case (MIRRORS the pharmacy `dispenses` recipe). One immutable
 * row per usage: the case, patient, item, quantity, the using clinician, when. Recording a usage DECREMENTS
 * stock (a 'used' `surgical_stock_movement`) ATOMICALLY. Append-only; patient-scoped read-logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_item_usages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('surgical_case_id');
            $table->ulid('patient_id');
            $table->ulid('surgical_item_id');
            $table->integer('quantity');
            $table->foreignId('used_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('used_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('surgical_case_id')->references('id')->on('surgical_cases')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('surgical_item_id')->references('id')->on('surgical_items')->cascadeOnDelete();

            $table->index(['tenant_id', 'surgical_case_id']);
            $table->index(['tenant_id', 'patient_id', 'used_at']);
        });

        DB::unprepared(<<<'SQL'
CREATE TRIGGER case_item_usages_no_update BEFORE UPDATE ON case_item_usages
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'case_item_usages are append-only: UPDATE is forbidden';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER case_item_usages_no_delete BEFORE DELETE ON case_item_usages
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'case_item_usages are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS case_item_usages_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS case_item_usages_no_delete');
        Schema::dropIfExists('case_item_usages');
    }
};
