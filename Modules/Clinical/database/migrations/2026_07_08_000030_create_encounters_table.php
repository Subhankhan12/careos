<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encounters', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('patient_id');
            $table->ulid('practitioner_id');
            $table->ulid('branch_id');
            $table->ulid('appointment_id')->nullable();
            $table->string('type');
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->string('status')->default('open');
            $table->text('reason_for_visit')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            $table->foreign('practitioner_id')->references('id')->on('staff_profiles')->restrictOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('appointment_id')->references('id')->on('appointments')->nullOnDelete();

            $table->index(['tenant_id', 'patient_id', 'started_at']);
            $table->index(['tenant_id', 'practitioner_id', 'started_at']);
            $table->index(['tenant_id', 'appointment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encounters');
    }
};
