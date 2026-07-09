<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');
            $table->string('title');
            $table->string('status')->default('active');
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->ulid('created_by');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('staff_profiles')->restrictOnDelete();

            $table->index(['tenant_id', 'patient_id', 'status']);
            $table->index(['tenant_id', 'created_by']);
        });

        Schema::create('care_plan_goals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('care_plan_id');
            $table->text('description');
            $table->date('target_date')->nullable();
            $table->string('status')->default('open');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('care_plan_id')->references('id')->on('care_plans')->cascadeOnDelete();

            $table->index(['tenant_id', 'care_plan_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_plan_goals');
        Schema::dropIfExists('care_plans');
    }
};
