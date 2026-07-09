<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('problems', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');
            $table->ulid('encounter_id')->nullable();
            $table->string('description');
            $table->string('code')->nullable();
            $table->date('onset_date')->nullable();
            $table->string('status')->default('active');
            $table->ulid('recorded_by');
            $table->dateTime('recorded_at');
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            $table->foreign('encounter_id')->references('id')->on('encounters')->nullOnDelete();
            $table->foreign('recorded_by')->references('id')->on('staff_profiles')->restrictOnDelete();

            $table->index(['tenant_id', 'patient_id', 'status']);
            $table->index(['tenant_id', 'encounter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('problems');
    }
};
