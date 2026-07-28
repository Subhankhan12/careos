<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ED.G1 — an EMERGENCY-DEPARTMENT presentation / visit. Per docs/HOSPITAL-PHASE6-ED-MAP.md §2.1 this is a
 * NET-NEW patient-FLOW entity: NOT a single-sitting Encounter, NOT an inpatient Stay (most ED visits discharge
 * home). The mutable CURRENT state (the flow `status`, the disposition set at the end); the immutable arrival +
 * transition history is `ed_visit_events` (append-only). Tenant + branch scoped, patient read-logged.
 *
 * ELECTRIC FENCE: a visit is an operational/flow record. `arrival_mode` + `disposition` are human-recorded
 * operational classifications (route / outcome — the `stays.admission_type` / `discharge_disposition`
 * precedent). There is deliberately NO acuity / triage / priority / severity / risk / score column — the
 * triage nurse ASSIGNS the acuity in a separate triage record (ED.G2); a computed triage acuity is the fence
 * line (map §3), a certified-partner / non-goal, NEVER here. The ED→ADT admit handoff detail (a soft
 * `stay_id`) arrives with the disposition workflow in ED.G5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ed_visits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');
            $table->ulid('branch_id');
            $table->dateTime('arrived_at');
            $table->string('arrival_mode');        // walk_in / ambulance / referral (an operational route)
            $table->string('chief_complaint');     // the presenting complaint recorded at arrival (free text)
            $table->string('status')->default('arrived'); // the legal-only flow machine (arrived → … → dispositioned)
            $table->string('disposition')->nullable();     // admit / discharge / transfer — recorded at the end (G5 detail)
            $table->dateTime('dispositioned_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();

            $table->index(['tenant_id', 'patient_id']);
            $table->index(['tenant_id', 'branch_id', 'status']); // the future tracking board (G3) reads this
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ed_visits');
    }
};
