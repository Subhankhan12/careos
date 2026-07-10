<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('payment_id');
            $table->bigInteger('amount_minor');
            $table->text('reason');
            $table->foreignId('refunded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('refunded_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->restrictOnDelete();

            $table->index(['tenant_id', 'payment_id']);
        });

        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_positive CHECK (amount_minor > 0)');

        // A refund is a separate append-only row referencing the payment; never a
        // negative payment and never an edit of the original payment row.
        DB::unprepared(
            "CREATE TRIGGER refunds_no_update BEFORE UPDATE ON refunds\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refunds is append-only: UPDATE is forbidden';"
        );
        DB::unprepared(
            "CREATE TRIGGER refunds_no_delete BEFORE DELETE ON refunds\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refunds is append-only: DELETE is forbidden';"
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS refunds_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS refunds_no_delete');
        Schema::dropIfExists('refunds');
    }
};
