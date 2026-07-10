<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');
            $table->string('payer_type');
            $table->string('payer_name')->nullable();
            $table->string('number')->nullable();
            $table->string('series')->default('INV');
            $table->string('status')->default('draft');
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('vat_total_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('open_balance_minor')->default(0);
            $table->ulid('credit_note_for_invoice_id')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            $table->foreign('credit_note_for_invoice_id')->references('id')->on('invoices')->restrictOnDelete();

            $table->unique(['tenant_id', 'series', 'number']);
            $table->index(['tenant_id', 'patient_id', 'status']);
        });

        Schema::table('charges', function (Blueprint $table): void {
            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
CREATE TRIGGER invoices_issued_no_update BEFORE UPDATE ON invoices
FOR EACH ROW
BEGIN
    IF OLD.status IN ('issued', 'paid', 'partially_paid', 'cancelled_by_credit_note') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'issued invoices are immutable: UPDATE is forbidden';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER invoices_issued_no_delete BEFORE DELETE ON invoices
FOR EACH ROW
BEGIN
    IF OLD.status IN ('issued', 'paid', 'partially_paid', 'cancelled_by_credit_note') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'issued invoices are immutable: DELETE is forbidden';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS invoices_issued_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS invoices_issued_no_delete');

        Schema::table('charges', function (Blueprint $table): void {
            $table->dropForeign(['invoice_id']);
        });

        Schema::dropIfExists('invoices');
    }
};
