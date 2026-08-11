<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Services\AdjustmentService;

/**
 * BILLAR.P1 — write-offs (bad debt) + contractual adjustments (insurer-agreed rate reconciliation)
 * as first-class, APPEND-ONLY ledger movements against an invoice.
 *
 * Each row REDUCES the invoice open balance as a real money movement (integer minor units), tied to
 * the invoice, with a required reason (and an optional agreement reference for contractual ones).
 * Signed minor units, exactly like `payment_allocations`: an adjustment is POSITIVE (reduces the open
 * balance); a correction is a REVERSAL row — the exact NEGATIVE of the adjustment it reverses — never
 * a mutation. `SUM(amount_minor)` yields the net applied amount with no drift, so the balance is
 * `total − net allocations − net adjustments` and the ReconciliationEngine's I2 ties out to the unit.
 *
 * Append-only at the DB (triggers) as well as the ORM guard; a non-zero CHECK matches the allocations
 * table. Operator-gated + audited in {@see AdjustmentService} — the billing
 * agent never writes one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_adjustments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('invoice_id');
            $table->string('type'); // write_off | contractual
            // POSITIVE reduces the open balance; a reversal row is the exact NEGATIVE of its target.
            $table->bigInteger('amount_minor');
            $table->ulid('reverses_adjustment_id')->nullable();
            $table->text('reason');
            $table->string('reference')->nullable(); // insurer agreement ref (contractual adjustments)
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            // DATETIME, not TIMESTAMP: MariaDB gives the first non-nullable TIMESTAMP an implicit
            // ON UPDATE CURRENT_TIMESTAMP (P0P.G15) — DATETIME is immune, so a recorded moment can never
            // be silently rewritten on any engine.
            $table->dateTime('adjusted_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnDelete();
            $table->foreign('reverses_adjustment_id')->references('id')->on('invoice_adjustments')->restrictOnDelete();

            $table->index(['tenant_id', 'invoice_id']);
            $table->index(['tenant_id', 'type']);
            $table->unique(['reverses_adjustment_id']);
        });

        DB::statement('ALTER TABLE invoice_adjustments ADD CONSTRAINT invoice_adjustments_amount_nonzero CHECK (amount_minor <> 0)');

        // A correction is a reversal ROW, never a delete/edit. Adjustments are frozen.
        DB::unprepared(
            "CREATE TRIGGER invoice_adjustments_no_update BEFORE UPDATE ON invoice_adjustments\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoice_adjustments is append-only: UPDATE is forbidden';"
        );
        DB::unprepared(
            "CREATE TRIGGER invoice_adjustments_no_delete BEFORE DELETE ON invoice_adjustments\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invoice_adjustments is append-only: DELETE is forbidden';"
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS invoice_adjustments_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS invoice_adjustments_no_delete');
        Schema::dropIfExists('invoice_adjustments');
    }
};
