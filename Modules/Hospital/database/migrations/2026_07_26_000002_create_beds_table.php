<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Inpatient bed (HOSPITAL.G1) — a NET-NEW model, NOT a Scheduling Resource
        // (see docs/HOSPITAL-PHASE1-ADT-MAP.md §2.1): a bed is occupied CONTINUOUSLY
        // for a multi-day stay, so there is deliberately NO starts_at/ends_at here —
        // occupancy is a stay (HOSPITAL.G2), not a timed slot. `status` is a purely
        // OPERATIONAL housekeeping state (free/occupied/cleaning/blocked) that can be
        // true with NO patient/service attached. Tenant-owned (BelongsToTenant).
        //
        // ELECTRIC FENCE: there is deliberately NO patient/acuity/severity/score/risk
        // field on a bed — status is housekeeping, never a clinical judgment.
        Schema::create('beds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('branch_id');
            $table->ulid('ward_id');
            $table->string('label');
            $table->string('bed_type');
            $table->string('status')->default('free');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('ward_id')->references('id')->on('wards')->cascadeOnDelete();

            // A bed label is unique within a ward (per tenant).
            $table->unique(['tenant_id', 'ward_id', 'label']);
            $table->index(['tenant_id', 'branch_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beds');
    }
};
