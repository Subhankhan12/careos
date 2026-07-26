<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Inpatient ward / nursing unit (HOSPITAL.G1). A ward groups beds under a
        // branch, belonging to a tenant. Tenant-owned (BelongsToTenant): every row
        // carries tenant_id. This is a Hospital-vertical domain model (it references
        // only the Platform foundation — Branch — exactly like Nursing\Visit) rather
        // than repurposing Platform's generic Department stub, so ward-specific
        // attributes can grow here without pushing inpatient concepts into Platform.
        Schema::create('wards', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('branch_id');
            $table->string('name');
            $table->string('code');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();

            // A ward code is unique within a branch (per tenant).
            $table->unique(['tenant_id', 'branch_id', 'code']);
            $table->index(['tenant_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wards');
    }
};
