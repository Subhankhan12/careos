<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SURGERY.G1 — a surgical CASE. Per docs/HOSPITAL-PHASE5-SURGERY-MAP.md §2.2 this is a NET-NEW entity (a
 * surgery is neither a single-sitting Encounter nor a fixed clinic slot). G1 ships the case + the `scheduled`
 * status only; the full lifecycle (scheduled → pre_op → in_progress → completed → post_op) + append-only case
 * events are SURGERY.G2. The mutable CURRENT state; patient + surgeon scoped, patient read-logged.
 *
 * `stay_id` is a SOFT nullable ref to a Phase-1 inpatient stay (NO FK/relation — Surgery stays arch-
 * independent of Hospital; null for day-surgery / outpatient), composed app-layer (the pharmacy `stay_id`
 * precedent). `procedure_description` is free text in G1 (a tenant-authored procedure catalog is a later gate).
 *
 * ELECTRIC FENCE: a case is an operational/scheduling record. There is deliberately NO computed acuity /
 * priority / risk / severity / triage / score column — a surgical-risk score is the fence line (map §3), a
 * certified-partner / non-goal, NEVER here. The ASA class (an anesthetist-ASSIGNED fact) arrives with the
 * anesthesia record in a later gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surgical_cases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');
            $table->ulid('primary_surgeon_id');          // the responsible surgeon (staff_profiles)
            $table->ulid('stay_id')->nullable();          // SOFT ref to an inpatient stay (no FK); null = day-surgery
            $table->string('procedure_description');       // free text in G1 (a procedure catalog is a later gate)
            $table->dateTime('scheduled_at');
            $table->string('status')->default('scheduled'); // G1 ships `scheduled`; the lifecycle machine is G2
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('primary_surgeon_id')->references('id')->on('staff_profiles')->cascadeOnDelete();

            $table->index(['tenant_id', 'patient_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgical_cases');
    }
};
