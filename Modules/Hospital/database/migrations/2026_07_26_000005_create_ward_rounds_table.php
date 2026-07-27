<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ward_rounds (HOSPITAL.G4) — the Hospital-SIDE link that ties an inpatient Stay to the
        // Clinical Encounters created during it (the map's §2.2 "Stay -> per-round Encounter"
        // decomposition). This is deliberately a Hospital table so CLINICAL STAYS UNTOUCHED — the
        // Encounter schema/invariant is not modified. A ward round IS a reused Clinical Encounter;
        // this row records "this encounter belongs to this stay." Bedside notes/vitals/orders hang
        // off the Encounter through the existing Clinical services (record-not-judge carries through).
        Schema::create('ward_rounds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('stay_id');
            $table->ulid('encounter_id');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('stay_id')->references('id')->on('stays')->cascadeOnDelete();
            $table->foreign('encounter_id')->references('id')->on('encounters')->cascadeOnDelete();

            $table->unique(['tenant_id', 'encounter_id']); // one round per encounter
            $table->index(['tenant_id', 'stay_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ward_rounds');
    }
};
