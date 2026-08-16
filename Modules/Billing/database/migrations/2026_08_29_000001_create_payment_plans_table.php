<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Services\PaymentPlanService;
use Modules\Billing\Services\PaymentService;

/**
 * ARDETAIL.P5 — an installment PAYMENT PLAN against a patient account's real outstanding balance.
 *
 * A plan SCHEDULES money; it never moves any. `total_minor` is the slice of the account's REAL
 * outstanding balance the plan covers — {@see PaymentPlanService::create()} refuses a total larger
 * than that outstanding, and refuses a second active plan on the same account, so a plan can never
 * schedule money that is not actually owed (no phantom money). The schedule itself is a real
 * PARTITION of that total: the sum of `payment_plan_installments.amount_minor` equals `total_minor`
 * exactly (the last installment absorbs the integer remainder), asserted at creation and locked by
 * tests. Integer minor units throughout.
 *
 * Paying an installment does NOT write money here: it goes through the guarded
 * {@see PaymentService} (ARDETAIL.P4) — over-allocation-guarded,
 * append-only, reconciling — and the installment merely records which Payment settled it.
 *
 * The plan row MUTATES (active → completed/cancelled/defaulted), so every recorded moment is
 * DATETIME, never TIMESTAMP: MariaDB 10.4 gives the first non-nullable TIMESTAMP an implicit
 * ON UPDATE CURRENT_TIMESTAMP (P0P.G15) and would silently rewrite it on the dev engine only.
 * Operator-gated + audited in the service; the billing agent has no path to create or commit one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');
            // The slice of the account's real outstanding this plan covers (never more than it).
            $table->bigInteger('total_minor');
            $table->string('currency', 3);
            $table->unsignedSmallInteger('installment_count');
            $table->date('start_date');
            $table->string('status')->default('active'); // active | completed | cancelled | defaulted
            // The outstanding the plan was measured against, recorded for provenance (the tie is
            // re-checked at creation; this preserves WHAT it tied to at that moment).
            $table->bigInteger('outstanding_at_creation_minor');
            $table->text('closed_reason')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('agreed_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();

            $table->index(['tenant_id', 'patient_id']);
            $table->index(['tenant_id', 'status']);
        });

        // A plan may never schedule a non-positive amount, and must have at least one installment.
        DB::statement('ALTER TABLE payment_plans ADD CONSTRAINT payment_plans_total_positive CHECK (total_minor > 0)');
        DB::statement('ALTER TABLE payment_plans ADD CONSTRAINT payment_plans_count_positive CHECK (installment_count > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_plans');
    }
};
