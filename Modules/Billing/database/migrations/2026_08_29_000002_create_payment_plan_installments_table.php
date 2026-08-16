<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ARDETAIL.P5 — the scheduled installments of a {@see payment_plans} row.
 *
 * THE PARTITION: `SUM(amount_minor)` over a plan's installments equals the plan's `total_minor`
 * EXACTLY — the service divides in integer minor units and gives the last installment the remainder,
 * so nothing is invented and nothing is lost (δ=0). Each row is a SCHEDULE line, not a money
 * movement: settling one records a real payment through the guarded PaymentService (ARDETAIL.P4) and
 * stores that `payment_id` here as the link. This table therefore carries no allocation, no balance
 * and no derived total — the reconciling ledger stays the single source of truth for money.
 *
 * Rows MUTATE (pending → paid), so `paid_at` is DATETIME, never TIMESTAMP (P0P.G15 — MariaDB 10.4
 * would give a non-nullable TIMESTAMP an implicit ON UPDATE and silently rewrite the moment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_plan_installments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('payment_plan_id');
            $table->unsignedSmallInteger('sequence');
            $table->date('due_date');
            $table->bigInteger('amount_minor');
            $table->string('status')->default('pending'); // pending | paid
            // The REAL payment (PaymentService, ARDETAIL.P4) that settled this installment.
            $table->ulid('payment_id')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('payment_plan_id')->references('id')->on('payment_plans')->cascadeOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->restrictOnDelete();

            $table->unique(['payment_plan_id', 'sequence']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'due_date']);
        });

        DB::statement('ALTER TABLE payment_plan_installments ADD CONSTRAINT payment_plan_installments_amount_positive CHECK (amount_minor > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_plan_installments');
    }
};
