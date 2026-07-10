<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Metadata ONLY (D-G1): room reference, parties, timestamps. There are
        // deliberately NO media columns and NO recording columns — media never
        // rests on CareOS servers and recording cannot exist (D-G2).
        Schema::create('telehealth_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('tenant_id', 26);
            $table->ulid('appointment_id')->nullable();
            $table->ulid('encounter_id')->nullable();
            $table->ulid('patient_id');
            $table->ulid('practitioner_id');
            $table->string('provider');
            $table->string('room_reference');
            $table->string('status')->default('created');
            // DATETIME, not TIMESTAMP (MariaDB implicit ON UPDATE wart): the
            // end-session update must never mutate started_at.
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('appointment_id')->references('id')->on('appointments')->restrictOnDelete();
            $table->foreign('encounter_id')->references('id')->on('encounters')->restrictOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            $table->foreign('practitioner_id')->references('id')->on('staff_profiles')->restrictOnDelete();

            $table->index(['tenant_id', 'patient_id', 'status']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telehealth_sessions');
    }
};
