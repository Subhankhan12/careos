<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAB.G2 — the thin lab-order overlay on the EXISTING Clinical `Order` (per docs/HOSPITAL-PHASE3-LAB-MAP.md
 * §2.4). A lab order IS a Clinical `Order` (placed via `OrderService::place`, REUSED — its lifecycle
 * `ordered → collected → in_progress → resulted → reviewed` + the `LabConnectivity` seam are Clinical's and
 * UNTOUCHED). This overlay adds ONLY the two lab-specific placement facts Clinical's `Order` doesn't carry:
 * the `specimen_type` for this order + the lab `priority` (routine/urgent/STAT). **Clinical's `Order` is NOT
 * modified** — STAT is overlay-only (Clinical's `Order.priority` accepts routine/urgent only, left at default).
 *
 * APPEND-ONLY (a placement record — model guards + `SIGNAL '45000'` DB triggers, the `order_results` recipe):
 * one immutable row per order. Patient-scoped for read logging.
 *
 * ELECTRIC FENCE (docs/HOSPITAL-PHASE3-LAB-MAP.md §4): `priority` is a RECORDED flag the ordering clinician
 * SETS (like the ED nurse-assigned acuity / `Stay.admission_type`) — the system does NOT compute a priority,
 * does NOT rank orders by a computed urgency, does NOT auto-escalate. There is deliberately NO
 * urgency-score/computed-priority/rank column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');   // denormalized for patient-scoped read logging + scoping
            $table->ulid('order_id');     // the Clinical Order (the lab order IS this Order — reused)
            $table->string('specimen_type')->nullable(); // the specimen for this order (defaults from the catalog)
            $table->string('priority');   // routine / urgent / stat — a RECORDED flag the clinician set, NOT computed
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();

            $table->unique('order_id'); // one lab overlay per Clinical Order
            $table->index(['tenant_id', 'patient_id']);
        });

        // Append-only: a lab-order placement is an immutable record of fact.
        DB::unprepared(<<<'SQL'
CREATE TRIGGER lab_orders_no_update BEFORE UPDATE ON lab_orders
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lab_orders are append-only: UPDATE is forbidden';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER lab_orders_no_delete BEFORE DELETE ON lab_orders
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lab_orders are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS lab_orders_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS lab_orders_no_delete');
        Schema::dropIfExists('lab_orders');
    }
};
