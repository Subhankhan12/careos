<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_tasks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id')->nullable();
            $table->ulid('care_plan_id')->nullable();
            $table->ulid('encounter_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->ulid('assigned_to');
            $table->dateTime('due_at');
            $table->string('priority')->default('normal');
            $table->string('status')->default('open');
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            $table->foreign('care_plan_id')->references('id')->on('care_plans')->nullOnDelete();
            $table->foreign('encounter_id')->references('id')->on('encounters')->nullOnDelete();
            $table->foreign('assigned_to')->references('id')->on('staff_profiles')->restrictOnDelete();

            $table->index(['tenant_id', 'assigned_to', 'status', 'due_at']);
            $table->index(['tenant_id', 'patient_id']);
            $table->index(['tenant_id', 'care_plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_tasks');
    }
};
