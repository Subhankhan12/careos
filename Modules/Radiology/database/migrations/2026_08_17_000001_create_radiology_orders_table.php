<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RAD.G2 — the thin imaging-order overlay on the EXISTING Clinical `Order` (the `lab_orders` precedent, per
 * docs/HOSPITAL-PHASE4-RADIOLOGY-MAP.md §2.4). An imaging order IS a Clinical `Order` (placed via
 * `OrderService::place`, REUSED — its lifecycle `ordered → collected → in_progress → resulted → reviewed` is
 * Clinical's and UNTOUCHED). This overlay adds ONLY the imaging-specific placement facts Clinical's `Order`
 * doesn't carry: the `modality` + `body_part` for this order + the imaging `priority`
 * (routine/urgent/STAT). **Clinical's `Order` is NOT modified** — STAT is overlay-only (Clinical's
 * `Order.priority` accepts routine/urgent only, left at default).
 *
 * APPEND-ONLY (a placement record — model guards + `SIGNAL '45000'` DB triggers, the `lab_orders` recipe):
 * one immutable row per order. Patient-scoped for read logging.
 *
 * ELECTRIC FENCE (§4): `priority` is a RECORDED flag the ordering clinician SETS (the LAB.G2 / ED nurse-assigned
 * acuity precedent) — the system does NOT compute a priority, does NOT rank by a computed urgency, does NOT
 * auto-escalate. There is deliberately NO urgency-score/computed-priority/rank column, and NO computed image
 * finding/CAD column (there is no image yet — this is order entry).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radiology_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');   // denormalized for patient-scoped read logging + scoping
            $table->ulid('order_id');     // the Clinical Order (the imaging order IS this Order — reused)
            $table->string('modality')->nullable();  // X-ray/CT/MRI/US — a plain recorded type (defaults from the exam)
            $table->string('body_part')->nullable(); // the body part for this order (defaults from the exam)
            $table->string('priority');   // routine / urgent / stat — a RECORDED flag the clinician set, NOT computed
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();

            $table->unique('order_id'); // one imaging overlay per Clinical Order
            $table->index(['tenant_id', 'patient_id']);
        });

        // Append-only: an imaging-order placement is an immutable record of fact.
        DB::unprepared(<<<'SQL'
CREATE TRIGGER radiology_orders_no_update BEFORE UPDATE ON radiology_orders
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'radiology_orders are append-only: UPDATE is forbidden';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER radiology_orders_no_delete BEFORE DELETE ON radiology_orders
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'radiology_orders are append-only: DELETE is forbidden';
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS radiology_orders_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS radiology_orders_no_delete');
        Schema::dropIfExists('radiology_orders');
    }
};
