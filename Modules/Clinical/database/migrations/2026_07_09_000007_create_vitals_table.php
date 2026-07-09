<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vitals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');
            $table->ulid('encounter_id')->nullable();
            $table->dateTime('recorded_at');
            $table->unsignedSmallInteger('systolic')->nullable();
            $table->unsignedSmallInteger('diastolic')->nullable();
            $table->unsignedSmallInteger('heart_rate')->nullable();
            $table->decimal('temperature_c', 4, 1)->nullable();
            $table->unsignedTinyInteger('spo2')->nullable();
            $table->unsignedInteger('weight_g')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->json('extra')->nullable();
            $table->ulid('recorded_by');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            $table->foreign('encounter_id')->references('id')->on('encounters')->nullOnDelete();
            $table->foreign('recorded_by')->references('id')->on('staff_profiles')->restrictOnDelete();

            $table->index(['tenant_id', 'patient_id', 'recorded_at']);
            $table->index(['tenant_id', 'encounter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vitals');
    }
};
