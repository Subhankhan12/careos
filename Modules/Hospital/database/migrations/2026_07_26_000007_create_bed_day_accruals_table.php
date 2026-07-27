<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bed_day_accruals — the Hospital-SIDE idempotency ledger for per-diem bed-day billing
 * (HOSPITAL.G6). One row per (stay, calendar day) that has been accrued, linking to the
 * Charge the EXISTING billing engine produced. The `unique(tenant_id, stay_id, service_date)`
 * key is the idempotency guard: `hospital:accrue-bed-days` run twice never double-charges — the
 * same discipline as Nursing's `unique(tenant_id, visit_plan_id, scheduled_date)` on planned_visits.
 * This is pure orchestration bookkeeping — NO money is stored here (the Charge holds the money,
 * priced + snapshotted by the engine).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bed_day_accruals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('stay_id');
            $table->date('service_date');
            $table->ulid('charge_id'); // the bed-day Charge the engine produced
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('stay_id')->references('id')->on('stays')->cascadeOnDelete();
            $table->foreign('charge_id')->references('id')->on('charges')->cascadeOnDelete();

            // The idempotency guard — one bed-day per stay per calendar date.
            $table->unique(['tenant_id', 'stay_id', 'service_date']);
            $table->index(['tenant_id', 'stay_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bed_day_accruals');
    }
};
