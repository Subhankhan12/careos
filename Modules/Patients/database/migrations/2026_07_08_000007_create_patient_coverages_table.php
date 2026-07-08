<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant-owned (BelongsToTenant): EU-generic coverage metadata only.
        Schema::create('patient_coverages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');
            $table->string('payer_name');
            $table->string('member_id');
            $table->string('plan')->nullable();
            $table->string('coverage_type');
            $table->integer('priority')->default(1);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();

            $table->index(['tenant_id', 'patient_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_coverages');
    }
};
