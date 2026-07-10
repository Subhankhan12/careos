<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('payment_id');
            $table->ulid('invoice_id');
            // Signed minor units: an allocation is POSITIVE, a reversal row is the
            // exact NEGATIVE of the allocation it reverses. Net arithmetic
            // (SUM(amount_minor)) yields the exact applied amount with no drift.
            $table->bigInteger('amount_minor');
            $table->ulid('reverses_allocation_id')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('allocated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('allocated_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->restrictOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnDelete();
            $table->foreign('reverses_allocation_id')->references('id')->on('payment_allocations')->restrictOnDelete();

            $table->index(['tenant_id', 'payment_id']);
            $table->index(['tenant_id', 'invoice_id']);
            $table->unique(['reverses_allocation_id']);
        });

        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_amount_nonzero CHECK (amount_minor <> 0)');

        // De-allocation is a reversal ROW, never a delete. Allocations are frozen.
        DB::unprepared(
            "CREATE TRIGGER payment_allocations_no_update BEFORE UPDATE ON payment_allocations\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_allocations is append-only: UPDATE is forbidden';"
        );
        DB::unprepared(
            "CREATE TRIGGER payment_allocations_no_delete BEFORE DELETE ON payment_allocations\n".
            "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_allocations is append-only: DELETE is forbidden';"
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS payment_allocations_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_allocations_no_delete');
        Schema::dropIfExists('payment_allocations');
    }
};
