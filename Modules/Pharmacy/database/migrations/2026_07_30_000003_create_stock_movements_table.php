<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHARMACY.G4 — the APPEND-ONLY stock ledger: one immutable row per stock change (received / dispensed /
 * adjusted), preserving the signed `quantity_change` + the `resulting_on_hand` after it. The current
 * `medication_stocks.on_hand` is kept consistent with the latest movement. Model guards + DB triggers (the
 * `medication_order_events` recipe) — a correction is a NEW movement, never an edit.
 *
 * ELECTRIC FENCE: operational facts only — no judgment/severity/score column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('medication_stock_id');
            $table->string('type');                 // received / dispensed / adjusted
            $table->integer('quantity_change');     // signed: + received, - dispensed, +/- adjusted
            $table->integer('resulting_on_hand');   // the on-hand AFTER this movement (a self-consistent ledger)
            $table->string('reason')->nullable();
            $table->ulid('dispense_id')->nullable(); // SOFT link to the dispense (for a 'dispensed' movement); no FK
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('medication_stock_id')->references('id')->on('medication_stocks')->cascadeOnDelete();

            // Explicit short index names (auto-generated names can exceed MySQL's 64-char identifier limit).
            $table->index(['tenant_id', 'medication_stock_id', 'occurred_at'], 'stock_mv_stock_at_idx');
        });

        // Append-only: a stock movement is an immutable ledger entry.
        DB::unprepared(<<<'SQL'
CREATE TRIGGER stock_movements_no_update BEFORE UPDATE ON stock_movements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements are append-only: UPDATE is forbidden';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER stock_movements_no_delete BEFORE DELETE ON stock_movements
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_delete');
        Schema::dropIfExists('stock_movements');
    }
};
