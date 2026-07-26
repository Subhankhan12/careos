<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An inpatient STAY / admission (HOSPITAL.G2) — a patient's multi-day inpatient
        // episode. Per docs/HOSPITAL-PHASE1-ADT-MAP.md §2.2 this is a NET-NEW entity ABOVE
        // the outpatient Encounter (which is left UNMODIFIED — its one-open-per-practitioner
        // invariant must hold for every vertical). The Stay is the mutable CURRENT state
        // (status, current bed/ward, discharge); the immutable admit/transfer/discharge
        // history lives in the append-only `stay_events` table. Tenant + branch scoped.
        //
        // ELECTRIC FENCE: ADT is OPERATIONAL — where the patient is, which bed. `admission_type`
        // (elective/emergency/transfer) is a human-recorded admission ROUTE, and
        // `discharge_disposition` an outcome fact — NOT a computed acuity/triage/severity score.
        // There is deliberately no acuity/severity/risk/score/deterioration column here.
        Schema::create('stays', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');
            $table->ulid('branch_id');
            $table->ulid('admitting_clinician_id');        // the responsible clinician (staff_profiles)
            $table->ulid('current_bed_id');                // where the patient is now (updated on transfer)
            $table->ulid('current_ward_id');               // denormalized from the bed for the ward board
            $table->dateTime('admitted_at');
            $table->dateTime('discharged_at')->nullable();
            $table->string('status')->default('admitted'); // admitted -> discharged (transfer stays admitted)
            $table->string('admission_type');              // elective / emergency / transfer (an operational route)
            $table->string('admission_reason')->nullable();
            $table->string('discharge_disposition')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('admitting_clinician_id')->references('id')->on('staff_profiles')->cascadeOnDelete();
            $table->foreign('current_bed_id')->references('id')->on('beds')->cascadeOnDelete();
            $table->foreign('current_ward_id')->references('id')->on('wards')->cascadeOnDelete();

            $table->index(['tenant_id', 'patient_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'current_ward_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stays');
    }
};
