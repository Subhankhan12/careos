<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHARMACY.G2 — the medication ORDER: a NET-NEW prescribing entity (the generic clinical `Order` has no
 * dose/route/frequency/PRN — don't force it; docs/HOSPITAL-PHASE2-PHARMACY-MAP.md §2.2). The MUTABLE
 * current state (status + the med-specific fields the clinician entered); the immutable history is
 * `medication_order_events` (append-only) — the Stay/StayEvent pattern.
 *
 * Tied to a Patient + the ordering clinician + a G1 `formulary_items` row (the tenant's OWN med — NO
 * licensed data). `stay_id` is a SOFT nullable reference to a Phase-1 inpatient stay (no FK — Pharmacy
 * stays arch-independent of Hospital; nullable also supports outpatient prescribing).
 *
 * ELECTRIC FENCE (record-not-judge): every column is what the CLINICIAN ordered — dose/route/frequency/PRN
 * are the clinician's ENTRIES. There is deliberately NO computed dose, NO suggested/recommended/ranked med,
 * NO safety verdict/severity/score/risk column. Medication-safety judgment lives ONLY behind the
 * MedicationSafetyProvider certified-partner seam (called advisorily at placement), never on this row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');
            $table->foreignId('prescribed_by')->constrained('users')->restrictOnDelete(); // the ordering clinician
            $table->ulid('formulary_item_id');
            $table->ulid('stay_id')->nullable(); // SOFT ref to a Hospital stay (inpatient); no FK, nullable for outpatient

            // The clinician's order — plain entries, never computed.
            $table->string('dose_amount');          // e.g. "500" or "1-2" — the clinician's entry
            $table->string('dose_unit');             // e.g. "mg" / "mL" / "tablet"
            $table->string('route');                 // a plain administration route (PO/IV/IM/SC/...)
            $table->string('frequency');             // a schedule descriptor (QID/BID/OD/PRN/...) — tenant-meaningful
            $table->dateTime('starts_at');
            $table->dateTime('stops_at')->nullable();
            $table->boolean('prn')->default(false);  // "as needed"
            $table->string('prn_reason')->nullable(); // free text — the clinician's PRN indication
            $table->text('note')->nullable();        // an optional order note / indication (clinician-authored)

            $table->string('status')->default('active'); // active -> held/discontinued/completed (legal-only)
            $table->string('status_reason')->nullable(); // the latest transition reason (also in the event log)
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('formulary_item_id')->references('id')->on('formulary_items')->restrictOnDelete();

            $table->index(['tenant_id', 'patient_id', 'status']);
            $table->index(['tenant_id', 'stay_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_orders');
    }
};
