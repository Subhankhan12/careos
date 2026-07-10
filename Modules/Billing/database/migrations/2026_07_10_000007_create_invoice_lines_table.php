<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('invoice_id');
            $table->ulid('charge_id')->nullable();
            $table->ulid('original_invoice_line_id')->nullable();
            $table->string('code');
            $table->text('description');
            $table->integer('quantity');
            $table->integer('unit_price_minor');
            $table->integer('vat_rate_bp');
            $table->bigInteger('line_total_minor');
            $table->bigInteger('line_vat_minor');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
            $table->foreign('charge_id')->references('id')->on('charges')->restrictOnDelete();
            $table->foreign('original_invoice_line_id')->references('id')->on('invoice_lines')->restrictOnDelete();

            $table->index(['tenant_id', 'invoice_id']);
            $table->index(['tenant_id', 'charge_id']);
        });

        DB::unprepared(<<<'SQL'
CREATE TRIGGER invoice_lines_issued_no_update BEFORE UPDATE ON invoice_lines
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM invoices
        WHERE invoices.id = OLD.invoice_id
          AND invoices.status IN ('issued', 'paid', 'partially_paid', 'cancelled_by_credit_note')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'issued invoice_lines are immutable: UPDATE is forbidden';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER invoice_lines_issued_no_delete BEFORE DELETE ON invoice_lines
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM invoices
        WHERE invoices.id = OLD.invoice_id
          AND invoices.status IN ('issued', 'paid', 'partially_paid', 'cancelled_by_credit_note')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'issued invoice_lines are immutable: DELETE is forbidden';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS invoice_lines_issued_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS invoice_lines_issued_no_delete');
        Schema::dropIfExists('invoice_lines');
    }
};
