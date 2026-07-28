<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SURGERY.G4 — the APPEND-ONLY surgical stock ledger (MIRRORS pharmacy `stock_movements`). One immutable row
 * per stock change (received / used / adjusted) with the signed `quantity_change` + `resulting_on_hand`; the
 * current `surgical_item_stocks.on_hand` stays consistent with the latest movement. A correction is a NEW
 * movement, never an edit (model guards + `SIGNAL '45000'` DB triggers). `case_item_usage_id` is a soft link
 * to the usage that caused a 'used' decrement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgical_stock_movements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('surgical_item_stock_id');
            $table->string('type');                 // received / used / adjusted
            $table->integer('quantity_change');     // signed
            $table->integer('resulting_on_hand');
            $table->string('reason')->nullable();
            $table->ulid('case_item_usage_id')->nullable(); // soft link (the usage that decremented)
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('surgical_item_stock_id')->references('id')->on('surgical_item_stocks')->cascadeOnDelete();

            $table->index(['tenant_id', 'surgical_item_stock_id', 'occurred_at'], 'surg_stock_mv_stock_at_idx');
        });

        DB::unprepared(<<<'SQL'
CREATE TRIGGER surgical_stock_movements_no_update BEFORE UPDATE ON surgical_stock_movements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'surgical_stock_movements are append-only: UPDATE is forbidden';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER surgical_stock_movements_no_delete BEFORE DELETE ON surgical_stock_movements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'surgical_stock_movements are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS surgical_stock_movements_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS surgical_stock_movements_no_delete');
        Schema::dropIfExists('surgical_stock_movements');
    }
};
