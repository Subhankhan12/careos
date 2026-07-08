<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned (BelongsToTenant): identifiers are optional attributes, not dedupe keys.
        Schema::create('patient_identifiers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');
            $table->string('system');
            $table->string('value');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();

            $table->index(['tenant_id', 'patient_id']);
            $table->index(['tenant_id', 'system', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_identifiers');
    }
};
