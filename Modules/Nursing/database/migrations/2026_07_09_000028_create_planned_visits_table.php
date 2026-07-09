<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planned_visits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('visit_plan_id');
            $table->ulid('patient_id');
            $table->date('scheduled_date');
            $table->dateTime('window_start_at');
            $table->dateTime('window_end_at');
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('required_qualification')->nullable();
            $table->string('status')->default('planned');
            $table->ulid('assigned_resource_id')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('visit_plan_id')->references('id')->on('visit_plans')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            $table->foreign('assigned_resource_id')->references('id')->on('resources')->nullOnDelete();

            $table->unique(['tenant_id', 'visit_plan_id', 'scheduled_date']);
            $table->index(['tenant_id', 'scheduled_date', 'status']);
            $table->index(['tenant_id', 'assigned_resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planned_visits');
    }
};
