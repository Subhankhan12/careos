<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAB.G3 — SPECIMEN TRACKING, the one genuine net-new lab entity (per docs/HOSPITAL-PHASE3-LAB-MAP.md §2.2 —
 * a Clinical `Order.status=collected` is a status, NOT a specimen). A specimen is collected from the patient
 * against a LAB.G2 lab order (`lab_order_id` → the reused Clinical Order underneath), accessioned with a
 * unique-per-tenant identifier (the MRN-generator precedent), and tracked through a legal-only state machine
 * (collected → in_lab → resulted; + rejected). The mutable CURRENT state; the immutable state history is
 * `specimen_events` (append-only). Tenant + patient scoped, patient read-logged.
 *
 * ELECTRIC FENCE (operational record-keeping): the state + accession are OPERATIONAL FACTS (where the specimen
 * is in the lab workflow / its identifier) — never a computed priority/urgency/routing judgment. There is
 * deliberately NO computed-priority/urgency/score/rank column. The STAT flag is the LAB.G2 clinician-recorded
 * flag on the order, not computed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specimens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');   // denormalized for patient-scoped read logging + scoping
            $table->ulid('lab_order_id'); // the LAB.G2 lab order (which links the reused Clinical Order)
            $table->string('accession_number'); // a unique-per-tenant lab identifier (a fact, generated)
            $table->string('specimen_type')->nullable();  // from the order overlay
            $table->string('container_type')->nullable();  // optional collection detail
            $table->string('collection_note')->nullable(); // optional
            $table->string('status')->default('collected'); // legal-only: collected → in_lab → resulted (+ rejected)
            $table->foreignId('collected_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('collected_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('lab_order_id')->references('id')->on('lab_orders')->cascadeOnDelete();

            $table->unique(['tenant_id', 'accession_number']); // the accession is unique per tenant
            $table->index(['tenant_id', 'lab_order_id']);
            $table->index(['tenant_id', 'patient_id']);
            $table->index(['tenant_id', 'status']); // the collection/lab worklist reads this
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('specimens');
    }
};
